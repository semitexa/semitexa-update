<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Exception\UpdateException;

/**
 * Read-only introspection of the live database — the only schema-aware code in
 * semitexa-update. Used by SchemaCompatibilityChecker to verify that a patch's
 * required tables/columns exist before the patch runs.
 *
 * This file does NOT issue DDL. It reads the system catalog (`sqlite_master` /
 * `pragma_table_info` for SQLite, `INFORMATION_SCHEMA` for MySQL). All structural
 * database changes belong to `semitexa-orm`.
 *
 * Results are cached per inspector instance — discovery + compat check is a single
 * pass and the live schema is stable for that pass.
 */
final class LiveSchemaInspector
{
    /** @var array<string, true>|null */
    private ?array $tableCache = null;

    /** @var array<string, array<string, true>> */
    private array $columnCache = [];

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
    ) {
    }

    public function hasTable(string $table): bool
    {
        if ($this->tableCache === null) {
            $this->tableCache = $this->loadTableSet();
        }
        return isset($this->tableCache[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        if (!isset($this->columnCache[$table])) {
            $this->columnCache[$table] = $this->loadColumnSet($table);
        }
        return isset($this->columnCache[$table][$column]);
    }

    /**
     * @return array<string, true>
     */
    private function loadTableSet(): array
    {
        if ($this->adapter instanceof SqliteAdapter) {
            $rows = $this->adapter->query(
                "SELECT name FROM sqlite_master WHERE type = 'table'"
            )->fetchAll();
            $set = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name !== '') {
                    $set[$name] = true;
                }
            }
            return $set;
        }

        if ($this->adapter instanceof MysqlAdapter) {
            $rows = $this->adapter->query(
                'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchAll();
            $set = [];
            foreach ($rows as $row) {
                $name = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
                if ($name !== '') {
                    $set[$name] = true;
                }
            }
            return $set;
        }

        throw new UpdateException(sprintf(
            'Unsupported database adapter: %s. LiveSchemaInspector supports MySQL and SQLite.',
            $this->adapter::class,
        ));
    }

    /**
     * @return array<string, true>
     */
    private function loadColumnSet(string $table): array
    {
        if ($this->adapter instanceof SqliteAdapter) {
            $rows = $this->adapter->query(
                sprintf("SELECT name FROM pragma_table_info(%s)", $this->quoteSqliteString($table))
            )->fetchAll();
            $set = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name !== '') {
                    $set[$name] = true;
                }
            }
            return $set;
        }

        if ($this->adapter instanceof MysqlAdapter) {
            $rows = $this->adapter->execute(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $table],
            )->fetchAll();
            $set = [];
            foreach ($rows as $row) {
                $name = (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
                if ($name !== '') {
                    $set[$name] = true;
                }
            }
            return $set;
        }

        throw new UpdateException(sprintf(
            'Unsupported database adapter: %s. LiveSchemaInspector supports MySQL and SQLite.',
            $this->adapter::class,
        ));
    }

    private function quoteSqliteString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
