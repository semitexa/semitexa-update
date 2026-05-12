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
        $this->reconcileLegacySchema();
        $this->schemaEnsured = true;
    }

    /**
     * Reconcile a journal table that was created by an older Semitexa release.
     *
     * The pre-2026.05 schema used a single `step_fqcn` identity column. The
     * current schema splits identity into (`module`, `patch_id`) and adds
     * `patch_fqcn`, `semitexa_version`, `applied_db_state_hash`. CREATE TABLE
     * IF NOT EXISTS leaves the legacy table untouched, so we additively
     * upgrade the column set, backfill identity from `step_fqcn`, and
     * swap the unique index. Idempotent and safe to run on fresh tables.
     */
    private function reconcileLegacySchema(): void
    {
        if ($this->db instanceof MysqlAdapter) {
            $this->reconcileLegacySchemaMysql();
            return;
        }
        if ($this->db instanceof SqliteAdapter) {
            $this->reconcileLegacySchemaSqlite();
        }
    }

    private function reconcileLegacySchemaMysql(): void
    {
        $columns = $this->mysqlColumns();
        if ($columns === []) {
            return;
        }

        $additions = [];
        if (!isset($columns['module'])) {
            $additions[] = 'ADD COLUMN module VARCHAR(255) NULL';
        }
        if (!isset($columns['patch_id'])) {
            $additions[] = 'ADD COLUMN patch_id VARCHAR(255) NULL';
        }
        if (!isset($columns['patch_fqcn'])) {
            $additions[] = 'ADD COLUMN patch_fqcn VARCHAR(255) NULL';
        }
        if (!isset($columns['semitexa_version'])) {
            $additions[] = 'ADD COLUMN semitexa_version VARCHAR(64) NULL';
        }
        if (!isset($columns['applied_db_state_hash'])) {
            $additions[] = 'ADD COLUMN applied_db_state_hash VARCHAR(64) NULL';
        }
        if ($additions !== []) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' ' . implode(', ', $additions));
            $columns = $this->mysqlColumns();
        }

        if (isset($columns['step_fqcn'])) {
            $this->db->query(
                'UPDATE ' . self::TABLE
                . " SET module = COALESCE(NULLIF(module, ''), 'legacy'),"
                . '     patch_id = COALESCE(NULLIF(patch_id, \'\'), step_fqcn),'
                . '     patch_fqcn = COALESCE(patch_fqcn, step_fqcn)'
                . ' WHERE step_fqcn IS NOT NULL'
            );
        }

        if (isset($columns['module']) && $columns['module']['nullable']) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' MODIFY COLUMN module VARCHAR(255) NOT NULL');
        }
        if (isset($columns['patch_id']) && $columns['patch_id']['nullable']) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' MODIFY COLUMN patch_id VARCHAR(255) NOT NULL');
        }
        if (isset($columns['step_fqcn']) && !$columns['step_fqcn']['nullable']) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' MODIFY COLUMN step_fqcn VARCHAR(255) NULL');
        }

        $indexes = $this->mysqlIndexes();
        if (in_array('uniq_step_fqcn', $indexes, true)) {
            $this->db->query('ALTER TABLE ' . self::TABLE . ' DROP INDEX uniq_step_fqcn');
        }
        if (!in_array('uniq_module_patch_id', $indexes, true)) {
            $this->db->query(
                'ALTER TABLE ' . self::TABLE
                . ' ADD UNIQUE KEY uniq_module_patch_id (module, patch_id)'
            );
        }
    }

    /**
     * @return array<string, array{nullable: bool}>
     */
    private function mysqlColumns(): array
    {
        $result = $this->db->query(
            "SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'"
        );
        $columns = [];
        foreach ($result->fetchAll() as $row) {
            $name = (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
            $nullable = strtoupper((string) ($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? 'NO')) === 'YES';
            if ($name !== '') {
                $columns[$name] = ['nullable' => $nullable];
            }
        }
        return $columns;
    }

    /**
     * @return list<string>
     */
    private function mysqlIndexes(): array
    {
        $result = $this->db->query(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "'"
        );
        $names = [];
        foreach ($result->fetchAll() as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? $row['index_name'] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function reconcileLegacySchemaSqlite(): void
    {
        $columns = $this->sqliteColumns();
        if ($columns === []) {
            return;
        }
        if (isset($columns['module'])) {
            return;
        }

        $hasStepFqcn = isset($columns['step_fqcn']);
        $legacyTable = self::TABLE . '__legacy';

        $this->db->query('ALTER TABLE ' . self::TABLE . ' RENAME TO ' . $legacyTable);
        $this->db->query($this->createTableSql());

        if ($hasStepFqcn) {
            $this->db->query(
                'INSERT INTO ' . self::TABLE
                . ' (id, module, patch_id, patch_fqcn, phase, checksum, status,'
                . '  semitexa_version, applied_db_state_hash,'
                . '  started_at, completed_at, duration_ms, error)'
                . " SELECT id, 'legacy', step_fqcn, step_fqcn, phase, checksum, status,"
                . '        NULL, NULL,'
                . '        started_at, completed_at, duration_ms, error'
                . ' FROM ' . $legacyTable
            );
        }

        $this->db->query('DROP TABLE ' . $legacyTable);
    }

    /**
     * @return array<string, array{nullable: bool}>
     */
    private function sqliteColumns(): array
    {
        $result = $this->db->query('PRAGMA table_info(' . self::TABLE . ')');
        $columns = [];
        foreach ($result->fetchAll() as $row) {
            $name = (string) ($row['name'] ?? '');
            $notnull = (int) ($row['notnull'] ?? 0);
            if ($name !== '') {
                $columns[$name] = ['nullable' => $notnull === 0];
            }
        }
        return $columns;
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
