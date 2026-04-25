<?php

declare(strict_types=1);

namespace Semitexa\Update\Schema;

use ReflectionClass;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Update\Discovery\DiscoveredPatch;

/**
 * Decides whether a data patch is safe to run against the live database.
 *
 * The check is the architectural boundary between Update and ORM:
 *   - ORM is the authority on the database shape (entities + tables + columns).
 *   - Update is allowed to *read* the shape via LiveSchemaInspector but never to
 *     change it. If the shape isn't ready for the patch, the runner aborts —
 *     it does NOT attempt to bring the shape forward (that's `orm:sync`'s job).
 *
 * The gate is composed of (in order):
 *   1. Semitexa version range (minSemitexa / maxSemitexa).
 *   2. Required entities — for each FQCN, derive its table from the [FromTable]
 *      attribute and assert the table is live; if the entity declares any
 *      [Column] attributes, every column name must also be live.
 *   3. Explicit requiresColumns map — every (table, column) pair must be live.
 *
 * Failure does not journal anything; the patch simply does not run.
 */
final class SchemaCompatibilityChecker
{
    public function __construct(
        private readonly LiveSchemaInspector $inspector,
    ) {
    }

    public function check(DiscoveredPatch $patch, ?string $semitexaVersion): CompatibilityResult
    {
        $reasons = [];

        if ($patch->minSemitexa !== null && $semitexaVersion !== null
            && version_compare($semitexaVersion, $patch->minSemitexa, '<')) {
            $reasons[] = sprintf(
                'requires Semitexa >= %s, current is %s',
                $patch->minSemitexa,
                $semitexaVersion,
            );
        }

        if ($patch->maxSemitexa !== null && $semitexaVersion !== null
            && version_compare($semitexaVersion, $patch->maxSemitexa, '>')) {
            $reasons[] = sprintf(
                'requires Semitexa <= %s, current is %s',
                $patch->maxSemitexa,
                $semitexaVersion,
            );
        }

        foreach ($patch->requires as $entityFqcn) {
            $reasons = array_merge($reasons, $this->checkRequiredEntity($entityFqcn));
        }

        foreach ($patch->requiresColumns as $table => $columns) {
            if (!$this->inspector->hasTable((string) $table)) {
                $reasons[] = sprintf("required table '%s' does not exist in the database", $table);
                continue;
            }
            foreach ($columns as $column) {
                if (!$this->inspector->hasColumn((string) $table, (string) $column)) {
                    $reasons[] = sprintf("required column '%s.%s' does not exist in the database", $table, $column);
                }
            }
        }

        return $reasons === []
            ? CompatibilityResult::compatible()
            : CompatibilityResult::incompatible($reasons);
    }

    /**
     * @return list<string>
     */
    private function checkRequiredEntity(string $entityFqcn): array
    {
        if (!class_exists($entityFqcn)) {
            return [sprintf("required entity %s is not autoloadable", $entityFqcn)];
        }

        $ref = new ReflectionClass($entityFqcn);
        $fromTableAttr = $ref->getAttributes(FromTable::class);
        if ($fromTableAttr === []) {
            return [sprintf(
                "required entity %s is not annotated with #[FromTable]; SchemaCompatibilityChecker cannot verify its table",
                $entityFqcn,
            )];
        }

        /** @var FromTable $fromTable */
        $fromTable = $fromTableAttr[0]->newInstance();
        $tableName = $this->extractTableName($fromTable);

        if ($tableName === null || $tableName === '') {
            return [sprintf(
                "required entity %s declares an unreadable table name on #[FromTable]",
                $entityFqcn,
            )];
        }

        if (!$this->inspector->hasTable($tableName)) {
            return [sprintf(
                "required entity %s expects table '%s', which does not exist in the database",
                $entityFqcn,
                $tableName,
            )];
        }

        $reasons = [];
        foreach ($ref->getProperties() as $property) {
            $columnAttr = $property->getAttributes(Column::class);
            if ($columnAttr === []) {
                continue;
            }
            $columnName = $this->extractColumnName($columnAttr[0]->newInstance(), $property->getName());
            if ($columnName === null || $columnName === '') {
                continue;
            }
            if (!$this->inspector->hasColumn($tableName, $columnName)) {
                $reasons[] = sprintf(
                    "required entity %s expects column %s.%s, which does not exist in the database",
                    $entityFqcn,
                    $tableName,
                    $columnName,
                );
            }
        }

        return $reasons;
    }

    private function extractTableName(FromTable $fromTable): ?string
    {
        // Tolerate either a public `name` property or a `name()` accessor — ORM
        // attribute shape may evolve; this read is best-effort.
        if (property_exists($fromTable, 'name')) {
            return (string) $fromTable->name;
        }
        if (method_exists($fromTable, 'name')) {
            return (string) $fromTable->name();
        }
        return null;
    }

    private function extractColumnName(Column $column, string $propertyDefault): ?string
    {
        if (property_exists($column, 'name') && $column->name !== null && $column->name !== '') {
            return (string) $column->name;
        }
        return $propertyDefault;
    }
}
