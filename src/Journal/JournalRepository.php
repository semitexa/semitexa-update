<?php

declare(strict_types=1);

namespace Semitexa\Update\Journal;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Enum\StepStatus;
use Semitexa\Update\Enum\UpdatePhase;
use Semitexa\Update\Exception\UpdateException;

/**
 * Append-in-spirit journal backed by a single table. Rows are inserted in
 * status=pending before a step runs and transitioned to applied/failed on
 * completion. Rows are never deleted; a step re-entering after a prior
 * failure updates the same row in place (keyed by step_fqcn).
 *
 * The schema is minimal-portable: string PK, ISO-8601 timestamps in TEXT.
 * Driver-specific DDL is limited to the TEXT vs VARCHAR choice.
 */
final class JournalRepository
{
    public const TABLE = 'platform_update_journal';

    private bool $schemaEnsured = false;

    public function __construct(
        private readonly DatabaseAdapterInterface $db,
    ) {
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }
        $this->db->query($this->createTableSql());
        $this->schemaEnsured = true;
    }

    /**
     * @return array<string, JournalEntry>  Keyed by step FQCN.
     */
    public function findAllByFqcn(): array
    {
        $this->ensureSchema();

        $result = $this->db->query(
            'SELECT id, step_fqcn, phase, checksum, status, started_at, completed_at, duration_ms, error '
            . 'FROM ' . self::TABLE
        );

        $entries = [];
        foreach ($result->fetchAll() as $row) {
            $entry = $this->hydrate($row);
            $entries[$entry->stepFqcn] = $entry;
        }
        return $entries;
    }

    public function findByFqcn(string $fqcn): ?JournalEntry
    {
        $this->ensureSchema();

        $result = $this->db->execute(
            'SELECT id, step_fqcn, phase, checksum, status, started_at, completed_at, duration_ms, error '
            . 'FROM ' . self::TABLE . ' WHERE step_fqcn = :fqcn',
            ['fqcn' => $fqcn],
        );

        $rows = $result->fetchAll();
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function markPending(string $fqcn, UpdatePhase $phase, string $checksum): void
    {
        $this->ensureSchema();

        $existing = $this->findByFqcn($fqcn);
        $now = $this->now();

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO ' . self::TABLE
                . ' (id, step_fqcn, phase, checksum, status, started_at, completed_at, duration_ms, error)'
                . ' VALUES (:id, :fqcn, :phase, :checksum, :status, :started_at, NULL, NULL, NULL)',
                [
                    'id'         => $this->newId(),
                    'fqcn'       => $fqcn,
                    'phase'      => $phase->value,
                    'checksum'   => $checksum,
                    'status'     => StepStatus::Pending->value,
                    'started_at' => $now,
                ],
            );
            return;
        }

        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET phase = :phase, checksum = :checksum, status = :status,'
            . '     started_at = :started_at, completed_at = NULL, duration_ms = NULL, error = NULL'
            . ' WHERE step_fqcn = :fqcn',
            [
                'phase'      => $phase->value,
                'checksum'   => $checksum,
                'status'     => StepStatus::Pending->value,
                'started_at' => $now,
                'fqcn'       => $fqcn,
            ],
        );
    }

    public function markApplied(string $fqcn, int $durationMs): void
    {
        $this->ensureSchema();

        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET status = :status, completed_at = :completed_at, duration_ms = :duration_ms, error = NULL'
            . ' WHERE step_fqcn = :fqcn',
            [
                'status'       => StepStatus::Applied->value,
                'completed_at' => $this->now(),
                'duration_ms'  => $durationMs,
                'fqcn'         => $fqcn,
            ],
        );
    }

    public function markFailed(string $fqcn, string $error, int $durationMs): void
    {
        $this->ensureSchema();

        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET status = :status, completed_at = :completed_at, duration_ms = :duration_ms, error = :error'
            . ' WHERE step_fqcn = :fqcn',
            [
                'status'       => StepStatus::Failed->value,
                'completed_at' => $this->now(),
                'duration_ms'  => $durationMs,
                'error'        => $error,
                'fqcn'         => $fqcn,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): JournalEntry
    {
        $phase  = UpdatePhase::tryFrom((string) ($row['phase']  ?? '')) ?? UpdatePhase::Deploy;
        $status = StepStatus::tryFrom((string) ($row['status'] ?? '')) ?? StepStatus::Pending;

        return new JournalEntry(
            id:         (string) ($row['id'] ?? ''),
            stepFqcn:   (string) ($row['step_fqcn'] ?? ''),
            phase:      $phase,
            checksum:   (string) ($row['checksum'] ?? ''),
            status:     $status,
            startedAt:  (string) ($row['started_at'] ?? ''),
            completedAt: isset($row['completed_at']) ? (string) $row['completed_at'] : null,
            durationMs: isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            error:      isset($row['error']) ? (string) $row['error'] : null,
        );
    }

    private function createTableSql(): string
    {
        if ($this->db instanceof SqliteAdapter) {
            return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . ' id TEXT PRIMARY KEY,'
                . ' step_fqcn TEXT NOT NULL UNIQUE,'
                . ' phase TEXT NOT NULL,'
                . ' checksum TEXT NOT NULL,'
                . ' status TEXT NOT NULL,'
                . ' started_at TEXT NOT NULL,'
                . ' completed_at TEXT NULL,'
                . ' duration_ms INTEGER NULL,'
                . ' error TEXT NULL'
                . ')';
        }

        if ($this->db instanceof MysqlAdapter) {
            return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . ' id VARCHAR(36) NOT NULL PRIMARY KEY,'
                . ' step_fqcn VARCHAR(255) NOT NULL,'
                . ' phase VARCHAR(32) NOT NULL,'
                . ' checksum VARCHAR(64) NOT NULL,'
                . ' status VARCHAR(16) NOT NULL,'
                . ' started_at VARCHAR(32) NOT NULL,'
                . ' completed_at VARCHAR(32) NULL,'
                . ' duration_ms INT NULL,'
                . ' error TEXT NULL,'
                . ' UNIQUE KEY uniq_step_fqcn (step_fqcn)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }

        throw new UpdateException(sprintf(
            'Unsupported database adapter: %s. V1 supports MySQL and SQLite.',
            $this->db::class,
        ));
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
