<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Update\Domain\Model\JournalEntry;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Domain\Enum\PatchStatus;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Update\Exception\UpdateException;

/**
 * Append-in-spirit journal backed by a single table. Rows are inserted in
 * status=pending before a patch runs and transitioned to applied/failed on
 * completion. Rows are never deleted; a patch re-entering after a prior
 * failure updates the same row in place.
 *
 * Identity is `(module, patch_id)` — stable across class renames. The class
 * FQCN is recorded as a diagnostic column.
 *
 * The CREATE TABLE statement here is the only DDL allowed in semitexa-update;
 * data patches must not issue schema changes.
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
     * @return array<string, JournalEntry>  Keyed by identity ("module:patch_id").
     */
    public function findAllByIdentity(): array
    {
        $this->ensureSchema();

        $result = $this->db->query(
            'SELECT id, module, patch_id, patch_fqcn, phase, checksum, status,'
            . ' semitexa_version, applied_db_state_hash,'
            . ' started_at, completed_at, duration_ms, error'
            . ' FROM ' . self::TABLE
        );

        $entries = [];
        foreach ($result->fetchAll() as $row) {
            $entry = $this->hydrate($row);
            $entries[$entry->identity()] = $entry;
        }
        return $entries;
    }

    public function findByIdentity(string $module, string $patchId): ?JournalEntry
    {
        $this->ensureSchema();

        $result = $this->db->execute(
            'SELECT id, module, patch_id, patch_fqcn, phase, checksum, status,'
            . ' semitexa_version, applied_db_state_hash,'
            . ' started_at, completed_at, duration_ms, error'
            . ' FROM ' . self::TABLE
            . ' WHERE module = :module AND patch_id = :patch_id',
            ['module' => $module, 'patch_id' => $patchId],
        );

        $rows = $result->fetchAll();
        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function markPending(
        string $module,
        string $patchId,
        ?string $patchFqcn,
        UpdatePhase $phase,
        string $checksum,
        ?string $semitexaVersion,
    ): void {
        $this->ensureSchema();

        $existing = $this->findByIdentity($module, $patchId);
        $now = $this->now();

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO ' . self::TABLE
                . ' (id, module, patch_id, patch_fqcn, phase, checksum, status,'
                . '  semitexa_version, applied_db_state_hash,'
                . '  started_at, completed_at, duration_ms, error)'
                . ' VALUES (:id, :module, :patch_id, :patch_fqcn, :phase, :checksum, :status,'
                . '         :semitexa_version, NULL,'
                . '         :started_at, NULL, NULL, NULL)',
                [
                    'id'               => $this->newId(),
                    'module'           => $module,
                    'patch_id'         => $patchId,
                    'patch_fqcn'       => $patchFqcn,
                    'phase'            => $phase->value,
                    'checksum'         => $checksum,
                    'status'           => PatchStatus::Pending->value,
                    'semitexa_version' => $semitexaVersion,
                    'started_at'       => $now,
                ],
            );
            return;
        }

        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET patch_fqcn = :patch_fqcn, phase = :phase, checksum = :checksum, status = :status,'
            . '     semitexa_version = :semitexa_version, applied_db_state_hash = NULL,'
            . '     started_at = :started_at, completed_at = NULL, duration_ms = NULL, error = NULL'
            . ' WHERE module = :module AND patch_id = :patch_id',
            [
                'patch_fqcn'       => $patchFqcn,
                'phase'            => $phase->value,
                'checksum'         => $checksum,
                'status'           => PatchStatus::Pending->value,
                'semitexa_version' => $semitexaVersion,
                'started_at'       => $now,
                'module'           => $module,
                'patch_id'         => $patchId,
            ],
        );
    }

    public function markApplied(
        string $module,
        string $patchId,
        int $durationMs,
        ?string $appliedDbStateHash,
    ): void {
        $this->ensureSchema();

        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET status = :status, completed_at = :completed_at, duration_ms = :duration_ms,'
            . '     applied_db_state_hash = :hash, error = NULL'
            . ' WHERE module = :module AND patch_id = :patch_id',
            [
                'status'       => PatchStatus::Applied->value,
                'completed_at' => $this->now(),
                'duration_ms'  => $durationMs,
                'hash'         => $appliedDbStateHash,
                'module'       => $module,
                'patch_id'     => $patchId,
            ],
        );
    }

    public function markFailed(string $module, string $patchId, string $error, int $durationMs): void
    {
        $this->ensureSchema();

        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET status = :status, completed_at = :completed_at, duration_ms = :duration_ms, error = :error'
            . ' WHERE module = :module AND patch_id = :patch_id',
            [
                'status'       => PatchStatus::Failed->value,
                'completed_at' => $this->now(),
                'duration_ms'  => $durationMs,
                'error'        => $error,
                'module'       => $module,
                'patch_id'     => $patchId,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): JournalEntry
    {
        $phase  = UpdatePhase::tryFrom((string) ($row['phase']  ?? '')) ?? UpdatePhase::Apply;
        $status = PatchStatus::tryFrom((string) ($row['status'] ?? '')) ?? PatchStatus::Pending;

        return new JournalEntry(
            id:                 (string) ($row['id'] ?? ''),
            module:             (string) ($row['module'] ?? ''),
            patchId:            (string) ($row['patch_id'] ?? ''),
            patchFqcn:          isset($row['patch_fqcn']) ? (string) $row['patch_fqcn'] : null,
            phase:              $phase,
            checksum:           (string) ($row['checksum'] ?? ''),
            status:             $status,
            semitexaVersion:    isset($row['semitexa_version']) ? (string) $row['semitexa_version'] : null,
            appliedDbStateHash: isset($row['applied_db_state_hash']) ? (string) $row['applied_db_state_hash'] : null,
            startedAt:          (string) ($row['started_at'] ?? ''),
            completedAt:        isset($row['completed_at']) ? (string) $row['completed_at'] : null,
            durationMs:         isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            error:              isset($row['error']) ? (string) $row['error'] : null,
        );
    }

    private function createTableSql(): string
    {
        if ($this->db instanceof SqliteAdapter) {
            return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . ' id TEXT PRIMARY KEY,'
                . ' module TEXT NOT NULL,'
                . ' patch_id TEXT NOT NULL,'
                . ' patch_fqcn TEXT NULL,'
                . ' phase TEXT NOT NULL,'
                . ' checksum TEXT NOT NULL,'
                . ' status TEXT NOT NULL,'
                . ' semitexa_version TEXT NULL,'
                . ' applied_db_state_hash TEXT NULL,'
                . ' started_at TEXT NOT NULL,'
                . ' completed_at TEXT NULL,'
                . ' duration_ms INTEGER NULL,'
                . ' error TEXT NULL,'
                . ' UNIQUE(module, patch_id)'
                . ')';
        }

        if ($this->db instanceof MysqlAdapter) {
            return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . ' id VARCHAR(36) NOT NULL PRIMARY KEY,'
                . ' module VARCHAR(255) NOT NULL,'
                . ' patch_id VARCHAR(255) NOT NULL,'
                . ' patch_fqcn VARCHAR(255) NULL,'
                . ' phase VARCHAR(32) NOT NULL,'
                . ' checksum VARCHAR(64) NOT NULL,'
                . ' status VARCHAR(16) NOT NULL,'
                . ' semitexa_version VARCHAR(64) NULL,'
                . ' applied_db_state_hash VARCHAR(64) NULL,'
                . ' started_at VARCHAR(32) NOT NULL,'
                . ' completed_at VARCHAR(32) NULL,'
                . ' duration_ms INT NULL,'
                . ' error TEXT NULL,'
                . ' UNIQUE KEY uniq_module_patch_id (module, patch_id)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }

        throw new UpdateException(sprintf(
            'Unsupported database adapter: %s. Supports MySQL and SQLite.',
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
