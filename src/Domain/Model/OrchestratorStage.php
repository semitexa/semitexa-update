<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

use Semitexa\Update\Domain\Model\Composer\ComposerUpdateResult;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport;
use Semitexa\Update\Domain\Model\SchemaSyncResult;

/**
 * One stage of the orchestrator's sweep.
 *
 * At most one of `report` / `syncResult` / `scaffoldReport` / `composerResult`
 * is non-null:
 *   - `report`         is set for the patch stages (pre-patches / apply-patches / post-patches)
 *   - `syncResult`     is set for the orm-sync stage
 *   - `scaffoldReport` is set for the scaffold-sync stage (uw-3)
 *   - `composerResult` is set for the composer-update stage
 *   - `preflightReport` is set for the preflight stage
 *   - all null: informational stage carrying only `message` (e.g. run-journal)
 */
final readonly class OrchestratorStage
{
    public function __construct(
        public string $name,
        public ?RunReport $report,
        public ?SchemaSyncResult $syncResult,
        public ?ScaffoldSyncReport $scaffoldReport = null,
        public ?ComposerUpdateResult $composerResult = null,
        public ?string $message = null,
        public ?PreflightReport $preflightReport = null,
    ) {
    }

    public function isSuccess(): bool
    {
        if ($this->report !== null) {
            return $this->report->isSuccess();
        }
        if ($this->scaffoldReport !== null) {
            return $this->scaffoldReport->isSuccess();
        }
        if ($this->composerResult !== null) {
            return $this->composerResult->isSuccess();
        }
        if ($this->preflightReport !== null) {
            return $this->preflightReport->isSuccess();
        }
        return true;
    }

    /**
     * Compact, JSON-safe recap of this stage for the run journal. Bounded by
     * construction: counts and one-line messages only, never raw tool output.
     *
     * @return array<string, mixed>
     */
    public function summarize(): array
    {
        $summary = ['name' => $this->name, 'success' => $this->isSuccess()];

        if ($this->composerResult !== null) {
            $summary['outcome'] = $this->composerResult->outcome->value;
            $summary['message'] = $this->composerResult->message;
            $summary['bumped_packages'] = count($this->composerResult->bumpedPackages);
            return $summary;
        }

        if ($this->scaffoldReport !== null) {
            $applied = 0;
            foreach ($this->scaffoldReport->results as $result) {
                if ($result->outcome === \Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome::Applied) {
                    $applied++;
                }
            }
            $summary['applied_files'] = $applied;
            if ($this->scaffoldReport->integrityErrors !== []) {
                $summary['integrity_errors'] = $this->scaffoldReport->integrityErrors;
            }
            return $summary;
        }

        if ($this->syncResult !== null) {
            $summary['message'] = $this->syncResult->summary;
            $summary['executed_operations'] = $this->syncResult->executedOperations;
            $summary['skipped_destructive'] = $this->syncResult->skippedDestructive;
            return $summary;
        }

        if ($this->report !== null) {
            $summary['applied_patches'] = count($this->report->applied);
            $summary['skipped_patches'] = count($this->report->skipped);
            $summary['duration_ms'] = $this->report->durationMs;
            if (!$this->report->isSuccess()) {
                $summary['failed_identity'] = $this->report->failedIdentity;
                $summary['error'] = $this->report->failedError;
            }
            return $summary;
        }

        if ($this->preflightReport !== null) {
            $summary['checks'] = count($this->preflightReport->checks);
            $failed = $this->preflightReport->failedChecks();
            if ($failed !== []) {
                $summary['failed_checks'] = array_map(
                    static fn (PreflightCheck $c): string => $c->name . ': ' . $c->message,
                    $failed,
                );
            }
            return $summary;
        }

        if ($this->message !== null) {
            $summary['message'] = $this->message;
        }
        return $summary;
    }
}
