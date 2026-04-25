<?php

declare(strict_types=1);

namespace Semitexa\Update\Runner;

use Semitexa\Update\Enum\UpdatePhase;
use Semitexa\Update\Migration\OrmMigrationGatewayInterface;
use Semitexa\Update\Migration\SchemaSyncResult;
use Semitexa\Update\Planner\Plan;

/**
 * Top-level coordinator of the update lifecycle. Stitches:
 *
 *   Pre patches  →  ORM schema sync  →  Apply patches  →  Post patches
 *
 * into a single sweep with abort-on-error semantics. The orchestrator never
 * issues DDL itself: it asks the OrmMigrationGateway to bring the schema
 * forward, then hands control back to the data-patch runner.
 *
 * If a Pre patch fails, the ORM sync is NOT attempted. If the ORM sync fails,
 * Apply/Post patches are NOT attempted. If an Apply patch fails, Post patches
 * are NOT attempted. The journal records exactly which patches ran.
 */
final class UpdateOrchestrator
{
    public function __construct(
        private readonly UpdateRunner $runner,
        private readonly OrmMigrationGatewayInterface $migrationGateway,
        private readonly string $connection,
    ) {
    }

    /**
     * Run the full Pre / ORM / Apply / Post sweep.
     *
     * @return list<OrchestratorStage> in execution order
     */
    public function run(bool $allowDestructive = false, bool $dryRun = false): array
    {
        $stages = [];
        $plan = $this->runner->plan();

        $pre = $this->runner->runPhases($plan, [UpdatePhase::Pre]);
        $stages[] = new OrchestratorStage('pre-patches', $pre, null);
        if (!$pre->isSuccess()) {
            return $stages;
        }

        $sync = $this->migrationGateway->synchronize(
            connection: $this->connection,
            allowDestructive: $allowDestructive,
            dryRun: $dryRun,
        );
        $stages[] = new OrchestratorStage('orm-sync', null, $sync);

        if ($dryRun) {
            // Re-plan after a fresh dry-run; do not execute Apply/Post against
            // a not-yet-applied schema.
            return $stages;
        }

        // Re-plan: a successful sync may have made previously-skipped Apply
        // patches eligible.
        $plan = $this->runner->plan();
        $apply = $this->runner->runPhases($plan, [UpdatePhase::Apply]);
        $stages[] = new OrchestratorStage('apply-patches', $apply, null);
        if (!$apply->isSuccess()) {
            return $stages;
        }

        $post = $this->runner->runPhases($plan, [UpdatePhase::Post]);
        $stages[] = new OrchestratorStage('post-patches', $post, null);

        return $stages;
    }

    /**
     * Read-only inspection of the current sweep state — what would happen if
     * `run()` were called now. The patch plan is computed against the current
     * schema; the ORM-sync step is reported via inspect() (no DDL issued).
     */
    public function plan(): OrchestratorPlanReport
    {
        $patchPlan = $this->runner->plan();
        $schemaStatus = $this->migrationGateway->inspect($this->connection);

        return new OrchestratorPlanReport($patchPlan, $schemaStatus);
    }
}
