<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

use Semitexa\Update\Domain\Model\Composer\ComposerUpdatePlan;
use Semitexa\Update\Domain\Model\PackageDrift\PackageDriftReport;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlan;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;
use Semitexa\Update\Domain\Model\Plan;

/**
 * Read-only snapshot of what `update` would do right now: the data-patch plan
 * partitioned by phase plus the ORM schema sync status. No side effects.
 *
 * `packageDrift` and `scaffoldPlan` are introduced by uw-1/uw-2/uw-3 and may
 * be null when the orchestrator is constructed without those collaborators
 * (older callers / unit-test fixtures).
 */
final readonly class OrchestratorPlanReport
{
    public function __construct(
        public Plan $patchPlan,
        public SchemaSyncStatus $schemaStatus,
        public ?PackageDriftReport $packageDrift = null,
        public ?ScaffoldSyncPlan $scaffoldPlan = null,
        public ?ComposerUpdatePlan $composerPlan = null,
    ) {
    }
}
