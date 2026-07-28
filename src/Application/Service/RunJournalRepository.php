<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Orm\Attribute\SelfManagedTable;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\UpdateRunRecord;
use Semitexa\Update\Exception\UpdateException;

/**
 * Journal of whole update runs — one row per invocation of the update sweep
 * or the auto-deploy pipeline. Complements {@see JournalRepository}, which
 * tracks individual data patches: this table answers "what happened when we
 * updated on date X" (stages, package version deltas, outcome, duration),
 * the patch journal answers "which patch is applied".
 *
 * Rows are inserted with outcome=running at run start and finalized once the
 * run ends. A row stuck in `running` is evidence of a run that died mid-way.
 * Rows are never deleted.
 *
 * Writes are best-effort by contract: callers must surface (not swallow) a
 * journal failure as a warning, but never let it fail the update itself.
 */
#[SelfManagedTable(self::TABLE)]
final class RunJournalRepository
{
    public const TABLE = 'platform_update_run_journal';

    public const KIND_UPDATE = 'update';
    public const KIND_AUTO_DEPLOY = 'auto-deploy';

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
        if (!$this->tableExists()) {
            $this->db->query($this->createTableSql());
        }
        $this->schemaEnsured = true;
    }

    /**
     * Read paths must stay read-only: a missing table simply means no run
     * has ever been recorded. Only begin() creates the table.
     */
    private function tableExists(): bool
    {
        if ($this->db instanceof SqliteAdapter) {
            $rows = $this->db->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . self::TABLE . "'"
            )->fetchAll();
            return $rows !== [];
        }

        if ($this->db instanceof MysqlAdapter) {
            $rows = $this->db->query(
                'SELECT 1 FROM information_schema.TABLES'
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'"
            )->fetchAll();
            return $rows !== [];
        }

        throw new UpdateException(sprintf(
            'Unsupported database adapter: %s. Supports MySQL and SQLite.',
            $this->db::class,
        ));
    }

    /**
     * Insert the run row in outcome=running and return its id.
     */
    public function begin(string $kind, ?string $actor, ?string $updaterVersion): string
    {
        $this->ensureSchema();

        $id = bin2hex(random_bytes(16));
        $this->db->execute(
            'INSERT INTO ' . self::TABLE
            . ' (id, kind, actor, updater_version, outcome, failed_stage, stages, package_deltas,'
            . '  patches_applied, error, started_at, completed_at, duration_ms)'
            . ' VALUES (:id, :kind, :actor, :updater_version, :outcome, NULL, NULL, NULL,'
            . '         0, NULL, :started_at, NULL, NULL)',
            [
                'id'              => $id,
                'kind'            => $kind,
                'actor'           => $actor,
                'updater_version' => $updaterVersion,
                'outcome'         => RunOutcome::Running->value,
                'started_at'      => $this->now(),
            ],
        );

        return $id;
    }

    /**
     * @param list<array<string, mixed>>                      $stages
     * @param array<string, array{from: ?string, to: string}> $packageDeltas
     */
    public function finish(
        string $id,
        RunOutcome $outcome,
        ?string $failedStage,
        array $stages,
        array $packageDeltas,
        int $patchesApplied,
        ?string $error,
    ): void {
        $this->ensureSchema();

        $completedAt = $this->now();
        $this->db->execute(
            'UPDATE ' . self::TABLE
            . ' SET outcome = :outcome, failed_stage = :failed_stage, stages = :stages,'
            . '     package_deltas = :package_deltas, patches_applied = :patches_applied,'
            . '     error = :error, completed_at = :completed_at, duration_ms = :duration_ms'
            . ' WHERE id = :id',
            [
                'outcome'         => $outcome->value,
                'failed_stage'    => $failedStage,
                'stages'          => json_encode($stages, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'package_deltas'  => json_encode($packageDeltas, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'patches_applied' => $patchesApplied,
                'error'           => $error,
                'completed_at'    => $completedAt,
                'duration_ms'     => $this->durationMs($id, $completedAt),
                'id'              => $id,
            ],
        );
    }

    /**
     * Most recent runs, newest first.
     *
     * @return list<UpdateRunRecord>
     */
    public function findRecent(int $limit = 20): array
    {
        if (!$this->schemaEnsured && !$this->tableExists()) {
            return [];
        }

        $result = $this->db->execute(
            'SELECT id, kind, actor, updater_version, outcome, failed_stage, stages, package_deltas,'
            . ' patches_applied, error, started_at, completed_at, duration_ms'
            . ' FROM ' . self::TABLE
            . ' ORDER BY started_at DESC'
            . ' LIMIT ' . max(1, $limit),
            [],
        );

        $records = [];
        foreach ($result->fetchAll() as $row) {
            $records[] = $this->hydrate($row);
        }
        return $records;
    }

    /**
     * Exact id or unique prefix (the history table shows truncated ids).
     * An ambiguous prefix returns null.
     */
    public function find(string $id): ?UpdateRunRecord
    {
        if ($id === '' || (!$this->schemaEnsured && !$this->tableExists())) {
            return null;
        }

        $result = $this->db->execute(
            'SELECT id, kind, actor, updater_version, outcome, failed_stage, stages, package_deltas,'
            . ' patches_applied, error, started_at, completed_at, duration_ms'
            . ' FROM ' . self::TABLE . " WHERE id LIKE :prefix ESCAPE '!' LIMIT 2",
            ['prefix' => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $id) . '%'],
        );

        $rows = $result->fetchAll();
        return count($rows) === 1 ? $this->hydrate($rows[0]) : null;
    }

    private function durationMs(string $id, string $completedAt): ?int
    {
        $result = $this->db->execute(
            'SELECT started_at FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => $id],
        );
        $rows = $result->fetchAll();
        if ($rows === []) {
            return null;
        }

        $started = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', (string) $rows[0]['started_at'], new \DateTimeZone('UTC'));
        $completed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $completedAt, new \DateTimeZone('UTC'));
        if ($started === false || $completed === false) {
            return null;
        }

        return (int) round(((float) $completed->format('U.u') - (float) $started->format('U.u')) * 1000);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): UpdateRunRecord
    {
        $stages = json_decode((string) ($row['stages'] ?? ''), true);
        $deltas = json_decode((string) ($row['package_deltas'] ?? ''), true);

        return new UpdateRunRecord(
            id:             (string) ($row['id'] ?? ''),
            kind:           (string) ($row['kind'] ?? ''),
            actor:          isset($row['actor']) ? (string) $row['actor'] : null,
            updaterVersion: isset($row['updater_version']) ? (string) $row['updater_version'] : null,
            outcome:        RunOutcome::tryFrom((string) ($row['outcome'] ?? '')) ?? RunOutcome::Running,
            failedStage:    isset($row['failed_stage']) ? (string) $row['failed_stage'] : null,
            stages:         is_array($stages) ? $stages : [],
            packageDeltas:  is_array($deltas) ? $deltas : [],
            patchesApplied: (int) ($row['patches_applied'] ?? 0),
            error:          isset($row['error']) ? (string) $row['error'] : null,
            startedAt:      (string) ($row['started_at'] ?? ''),
            completedAt:    isset($row['completed_at']) ? (string) $row['completed_at'] : null,
            durationMs:     isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
        );
    }

    private function createTableSql(): string
    {
        if ($this->db instanceof SqliteAdapter) {
            return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . ' id TEXT PRIMARY KEY,'
                . ' kind TEXT NOT NULL,'
                . ' actor TEXT NULL,'
                . ' updater_version TEXT NULL,'
                . ' outcome TEXT NOT NULL,'
                . ' failed_stage TEXT NULL,'
                . ' stages TEXT NULL,'
                . ' package_deltas TEXT NULL,'
                . ' patches_applied INTEGER NOT NULL DEFAULT 0,'
                . ' error TEXT NULL,'
                . ' started_at TEXT NOT NULL,'
                . ' completed_at TEXT NULL,'
                . ' duration_ms INTEGER NULL'
                . ')';
        }

        if ($this->db instanceof MysqlAdapter) {
            return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . ' id VARCHAR(36) NOT NULL PRIMARY KEY,'
                . ' kind VARCHAR(16) NOT NULL,'
                . ' actor VARCHAR(128) NULL,'
                . ' updater_version VARCHAR(64) NULL,'
                . ' outcome VARCHAR(16) NOT NULL,'
                . ' failed_stage VARCHAR(64) NULL,'
                . ' stages MEDIUMTEXT NULL,'
                . ' package_deltas MEDIUMTEXT NULL,'
                . ' patches_applied INT NOT NULL DEFAULT 0,'
                . ' error TEXT NULL,'
                . ' started_at VARCHAR(32) NOT NULL,'
                . ' completed_at VARCHAR(32) NULL,'
                . ' duration_ms INT NULL,'
                . ' KEY idx_started_at (started_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }

        throw new UpdateException(sprintf(
            'Unsupported database adapter: %s. Supports MySQL and SQLite.',
            $this->db::class,
        ));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
