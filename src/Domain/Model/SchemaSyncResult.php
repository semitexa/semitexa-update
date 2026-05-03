<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

/**
 * Outcome of OrmMigrationGatewayInterface::synchronize().
 *
 * `executedOperations` is the count the ORM reports after running its plan;
 * `dryRun = true` indicates no DDL was issued. `summary` carries the human-readable
 * recap the operator-facing CLI surfaces (e.g. "Executed 3 operation(s).").
 */
final readonly class SchemaSyncResult
{
    public function __construct(
        public int $executedOperations,
        public int $skippedDestructive,
        public bool $dryRun,
        public string $summary,
    ) {
    }
}
