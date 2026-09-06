<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Update\Discovery\UpdateAdvisoryDiscovery;
use Semitexa\Update\Application\Service\Composer\ComposerUpdateRunner;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldSyncEngine;
use Semitexa\Update\Domain\Enum\ComposerUpdateOutcome;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\Composer\ComposerUpdatePlan;
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
        private readonly ?UpdateAdvisoryDiscovery $advisories = null,
        private readonly ?ScaffoldManifestLoader $scaffoldLoader = null,
        private readonly ?ScaffoldFileClassifier $scaffoldClassifier = null,
        private readonly ?ScaffoldSyncEngine $scaffoldEngine = null,
        private readonly ?string $projectRoot = null,
        private readonly ?string $scaffoldRoot = null,
        private readonly ?string $manifestPath = null,
        private readonly ?ComposerUpdateRunner $composerRunner = null,
        private readonly ?RunJournalRepository $runJournal = null,
        private readonly ?string $actor = null,
        private readonly ?string $updaterVersion = null,
        private readonly ?UpdateLock $lock = null,
        private readonly ?PreflightChecker $preflight = null,
        private readonly ?HealthChecker $healthChecker = null,
        private readonly ?string $healthcheckUrl = null,
    ) {
    }

    /**
     * Execute the sweep and record it in the run journal.
     *
     * Concurrency: mutating runs (not dry-run) take an exclusive flock-based
     * lock shared with the auto-deploy pipeline; a second concurrent run
     * fails fast with the holder's identity instead of racing on
     * composer.json / vendor / the journal.
     *
     * Journal semantics: mutating runs get one row — inserted as `running`
     * before the first stage, finalized after the last. Journal failures
     * never fail the update; they surface as a trailing `run-journal` stage
     * carrying a warning message so the operator still sees them.
     *
     * @return list<OrchestratorStage> in execution order
     */
    public function run(
        bool $allowDestructive = false,
        bool $dryRun = false,
        bool $writeCandidates = false,
        bool $skipComposer = false,
        bool $composerOnly = false,
        bool $allowPartialComposer = false,
    ): array {
        $lock = $dryRun ? null : $this->lock;
        if ($lock !== null && !$lock->acquire($this->actor ?? 'update')) {
            throw new \Semitexa\Update\Exception\UpdateException(sprintf(
                'Another update run is already in progress (%s). Wait for it to finish; '
                . 'a crashed run releases the lock automatically.',
                $lock->holderDescription(),
            ));
        }

        try {
            return $this->runLocked(
                $allowDestructive,
                $dryRun,
                $writeCandidates,
                $skipComposer,
                $composerOnly,
                $allowPartialComposer,
            );
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return list<OrchestratorStage> in execution order
     */
    private function runLocked(
        bool $allowDestructive,
        bool $dryRun,
        bool $writeCandidates,
        bool $skipComposer,
        bool $composerOnly,
        bool $allowPartialComposer,
    ): array {
        $journal = $dryRun ? null : $this->runJournal;
        $runId = null;
        $journalNote = null;
        if ($journal !== null) {
            try {
                $runId = $journal->begin(RunJournalRepository::KIND_UPDATE, $this->actor, $this->updaterVersion);
            } catch (\Throwable $e) {
                $journalNote = 'WARNING: run journal unavailable, this run will not be recorded: ' . $e->getMessage();
            }
        }

        try {
            $stages = $this->executeStages(
                $allowDestructive,
                $dryRun,
                $writeCandidates,
                $skipComposer,
                $composerOnly,
                $allowPartialComposer,
            );
        } catch (\Throwable $e) {
            if ($journal !== null && $runId !== null) {
                try {
                    $journal->finish($runId, RunOutcome::Failed, 'exception', [], [], 0, $e->getMessage());
                } catch (\Throwable) {
                    // The original exception is what the operator must see.
                }
            }
            throw $e;
        }

        if ($journal !== null && $runId !== null) {
            try {
                $outcome = $this->finalizeRunRecord($journal, $runId, $stages);
                $journalNote = sprintf('Recorded run %s (outcome: %s).', $runId, $outcome->value);
            } catch (\Throwable $e) {
                $journalNote = 'WARNING: run finished but could not be recorded in the run journal: ' . $e->getMessage();
            }
        }

        if ($journalNote !== null) {
            $stages[] = new OrchestratorStage('run-journal', null, null, message: $journalNote);
        }

        return $stages;
    }

    /**
     * @param list<OrchestratorStage> $stages
     */
    private function finalizeRunRecord(RunJournalRepository $journal, string $runId, array $stages): RunOutcome
    {
        $failedStage = null;
        $error = null;
        $packageDeltas = [];
        $patchesApplied = 0;
        $updaterChanged = false;
        $stateChanged = false;

        foreach ($stages as $stage) {
            if (!$stage->isSuccess() && $failedStage === null) {
                $failedStage = $stage->name;
                $error = $this->stageError($stage);
            }
            if ($stage->composerResult !== null) {
                $packageDeltas = $stage->composerResult->bumpedPackages;
                $updaterChanged = $stage->composerResult->outcome === ComposerUpdateOutcome::UpdaterChanged;
            }
            if ($stage->report !== null) {
                $patchesApplied += count($stage->report->applied);
            }
            if ($stage->syncResult !== null && $stage->syncResult->executedOperations > 0) {
                $stateChanged = true;
            }
            if ($stage->scaffoldReport !== null && ($stage->summarize()['applied_files'] ?? 0) > 0) {
                $stateChanged = true;
            }
        }

        $stateChanged = $stateChanged || $packageDeltas !== [] || $patchesApplied > 0;

        $outcome = match (true) {
            $failedStage !== null => RunOutcome::Failed,
            $updaterChanged       => RunOutcome::Aborted,
            $stateChanged         => RunOutcome::Success,
            default               => RunOutcome::Noop,
        };
        if ($outcome === RunOutcome::Aborted) {
            $error = 'semitexa/update was upgraded mid-run; rerun `bin/semitexa update` to continue.';
        }

        $journal->finish(
            $runId,
            $outcome,
            $failedStage,
            array_map(static fn (OrchestratorStage $s): array => $s->summarize(), $stages),
            $packageDeltas,
            $patchesApplied,
            $error,
        );

        return $outcome;
    }

    private function stageError(OrchestratorStage $stage): ?string
    {
        if ($stage->report !== null) {
            return $stage->report->failedError;
        }
        if ($stage->composerResult !== null) {
            return $stage->composerResult->message;
        }
        if ($stage->scaffoldReport !== null && $stage->scaffoldReport->integrityErrors !== []) {
            return implode('; ', $stage->scaffoldReport->integrityErrors);
        }
        if ($stage->preflightReport !== null) {
            return implode('; ', array_map(
                static fn (\Semitexa\Update\Domain\Model\PreflightCheck $c): string => $c->name . ': ' . $c->message,
                $stage->preflightReport->failedChecks(),
            ));
        }
        return null;
    }

    /**
     * @return list<OrchestratorStage> in execution order
     */
    private function executeStages(
        bool $allowDestructive,
        bool $dryRun,
        bool $writeCandidates,
        bool $skipComposer,
        bool $composerOnly,
        bool $allowPartialComposer,
    ): array {
        $stages = [];

        // Preflight is read-only and runs first: a failed check must abort
        // before composer, scaffold, or the database are touched.
        if ($this->preflight !== null) {
            $preflightReport = $this->preflight->check();
            $stages[] = new OrchestratorStage('preflight', null, null, preflightReport: $preflightReport);
            if (!$preflightReport->isSuccess()) {
                return $stages;
            }
        }

        // Composer phase first: changes vendor/, which everything downstream
        // depends on. If it fails or upgrades semitexa/update itself we stop.
        // --composer-only also acts as an explicit "force composer to run"
        // override — the operator chose to spend a composer call here, so
        // the runner's "skip when no bumps + no drift" optimization yields
        // to that intent.
        $composerResult = $this->runComposerUpdate(
            $dryRun,
            $skipComposer,
            $allowPartialComposer,
            forceComposer: $composerOnly,
        );
        if ($composerResult !== null) {
            $stages[] = new OrchestratorStage('composer-update', null, null, null, $composerResult);
            if (!$composerResult->isSuccess()) {
                return $stages;
            }
            if ($composerResult->outcome === ComposerUpdateOutcome::UpdaterChanged) {
                // Updater package was upgraded — running PHP class definitions
                // are now stale. Refuse to continue with the in-process code.
                return $stages;
            }
            if ($composerOnly) {
                return $stages;
            }
        }

        $scaffoldReport = $this->runScaffoldSync($dryRun, $writeCandidates);
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
        if (!$post->isSuccess()) {
            return $stages;
        }

        // Post-update smoke: when a health URL is configured the sweep only
        // counts as successful if the application actually answers.
        if ($this->healthChecker !== null && $this->healthcheckUrl !== null) {
            $check = $this->healthChecker->check($this->healthcheckUrl);
            $stages[] = new OrchestratorStage(
                'health-check',
                null,
                null,
                preflightReport: new \Semitexa\Update\Domain\Model\PreflightReport([$check]),
            );
        }

        return $stages;
    }

    /**
     * Read-only inspection of the current sweep state.
     */
    public function plan(bool $writeCandidates = false): OrchestratorPlanReport
    {
        $patchPlan = $this->runner->plan();
        $schemaStatus = $this->migrationGateway->inspect($this->connection);

        return new OrchestratorPlanReport(
            patchPlan: $patchPlan,
            schemaStatus: $schemaStatus,
            packageDrift: $this->inspectPackageDrift(),
            scaffoldPlan: $this->planScaffoldSync($writeCandidates),
            composerPlan: $this->planComposerUpdate(),
            advisories: $this->advisories?->collect() ?? [],
        );
    }

    private function planComposerUpdate(): ?ComposerUpdatePlan
    {
        if ($this->composerRunner === null || $this->projectRoot === null) {
            return null;
        }
        return $this->composerRunner->plan($this->projectRoot);
    }

    private function runComposerUpdate(
        bool $dryRun,
        bool $skipComposer,
        bool $allowPartial = false,
        bool $forceComposer = false,
    ): ?\Semitexa\Update\Domain\Model\Composer\ComposerUpdateResult {
        if ($this->composerRunner === null || $this->projectRoot === null) {
            return null;
        }
        return $this->composerRunner->execute(
            $this->projectRoot,
            $dryRun,
            $skipComposer,
            $allowPartial,
            force: $forceComposer,
        );
    }

    private function inspectPackageDrift(): ?PackageDriftReport
    {
        if ($this->driftInspector === null || $this->projectRoot === null) {
            return null;
        }
        return $this->driftInspector->inspect($this->projectRoot);
    }

    private function planScaffoldSync(bool $writeCandidates = false): ?ScaffoldSyncPlan
    {
        if (!$this->scaffoldCollaboratorsConfigured()) {
            return null;
        }
        $manifest = $this->scaffoldLoader->load($this->manifestPath);
        return $this->scaffoldClassifier->plan(
            projectRoot: $this->projectRoot,
            scaffoldRoot: $this->scaffoldRoot,
            manifest: $manifest,
            writeCandidates: $writeCandidates,
        );
    }

    private function runScaffoldSync(bool $dryRun, bool $writeCandidates = false): ?\Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport
    {
        if (!$this->scaffoldCollaboratorsConfigured()) {
            return null;
        }
        $plan = $this->planScaffoldSync($writeCandidates);
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
