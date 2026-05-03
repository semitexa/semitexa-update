<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

use Semitexa\Update\Domain\Model\SchemaSyncStatus;
use Semitexa\Update\Domain\Model\Plan;

/**
 * Read-only snapshot of what `update` would do right now: the data-patch plan
 * partitioned by phase plus the ORM schema sync status. No side effects.
 */
final readonly class OrchestratorPlanReport
{
    public function __construct(
        public Plan $patchPlan,
        public SchemaSyncStatus $schemaStatus,
    ) {
    }
}
