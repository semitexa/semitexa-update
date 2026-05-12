<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldSyncEngine;
use Semitexa\Update\Domain\Model\OrchestratorStage;
use Semitexa\Update\Domain\Model\OrchestratorPlanReport;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Update\Domain\Contract\OrmMigrationGatewayInterface;
use Semitexa\Update\Domain\Model\PackageDrift\PackageDriftReport;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlan;
use Semitexa\Update\Domain\Model\Plan;

/**
 * Top-level coordinator of the update lifecycle.
 *
 * Stage order:
 *
 *   package-drift  →  scaffold-sync  →  pre-patches  →  orm-sync
 *                  →  apply-patches  →  post-patches
 *
 * Stage semantics:
 *
 *  - package-drift is read-only. It surfaces composer.json/lock/installed.json
 *    drift across `semitexa/*` and is purely informational — Composer is never
 *    invoked. Drift never aborts the run.
 *  - scaffold-sync runs the manifest-driven classifier + engine from uw-2/uw-3.
 *    A scaffold integrity failure (manifest disagrees with the scaffold dir)
 *    aborts the run before any DB-affecting stage executes. A successful run
 *    may produce backups + .new files; locally modified files are never
 *    overwritten.
 *  - The remaining four stages keep their pre-existing semantics: abort-on-error
 *    for Pre, orm-sync, Apply; Post runs only after Apply succeeds.
 *
 * Collaborators driftInspector / scaffoldLoader / scaffoldClassifier /
 * scaffoldEngine / projectRoot / scaffoldRoot / manifestPath may be null,
 * which preserves the pre-uw-4 behavior (no drift / scaffold stages).
 */
final class UpdateOrchestrator
{
    public function __construct(
        private readonly UpdateRunner $runner,
        private readonly OrmMigrationGatewayInterface $migrationGateway,
        private readonly string $connection,
        private readonly ?PackageDriftInspector $driftInspector = null,
        private readonly ?ScaffoldManifestLoader $scaffoldLoader = null,
        private readonly ?ScaffoldFileClassifier $scaffoldClassifier = null,
        private readonly ?ScaffoldSyncEngine $scaffoldEngine = null,
        private readonly ?string $projectRoot = null,
        private readonly ?string $scaffoldRoot = null,
        private readonly ?string $manifestPath = null,
    ) {
    }

    /**
     * @return list<OrchestratorStage> in execution order
     */
    public function run(bool $allowDestructive = false, bool $dryRun = false): array
    {
        $stages = [];

        $scaffoldReport = $this->runScaffoldSync($dryRun);
        if ($scaffoldReport !== null) {
            $stages[] = new OrchestratorStage('scaffold-sync', null, null, $scaffoldReport);
            if (!$scaffoldReport->isSuccess()) {
                return $stages;
            }
        }

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
            return $stages;
        }

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
     * Read-only inspection of the current sweep state.
     */
    public function plan(): OrchestratorPlanReport
    {
        $patchPlan = $this->runner->plan();
        $schemaStatus = $this->migrationGateway->inspect($this->connection);

        return new OrchestratorPlanReport(
            patchPlan: $patchPlan,
            schemaStatus: $schemaStatus,
            packageDrift: $this->inspectPackageDrift(),
            scaffoldPlan: $this->planScaffoldSync(),
        );
    }

    private function inspectPackageDrift(): ?PackageDriftReport
    {
        if ($this->driftInspector === null || $this->projectRoot === null) {
            return null;
        }
        return $this->driftInspector->inspect($this->projectRoot);
    }

    private function planScaffoldSync(): ?ScaffoldSyncPlan
    {
        if (!$this->scaffoldCollaboratorsConfigured()) {
            return null;
        }
        $manifest = $this->scaffoldLoader->load($this->manifestPath);
        return $this->scaffoldClassifier->plan(
            projectRoot: $this->projectRoot,
            scaffoldRoot: $this->scaffoldRoot,
            manifest: $manifest,
        );
    }

    private function runScaffoldSync(bool $dryRun): ?\Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport
    {
        if (!$this->scaffoldCollaboratorsConfigured()) {
            return null;
        }
        $plan = $this->planScaffoldSync();
        if ($plan === null) {
            return null;
        }
        return $this->scaffoldEngine->execute(
            $plan,
            $this->projectRoot,
            $this->scaffoldRoot,
            $dryRun,
        );
    }

    private function scaffoldCollaboratorsConfigured(): bool
    {
        return $this->scaffoldLoader !== null
            && $this->scaffoldClassifier !== null
            && $this->scaffoldEngine !== null
            && $this->projectRoot !== null
            && $this->scaffoldRoot !== null
            && $this->manifestPath !== null;
    }
}
