<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport;
use Semitexa\Update\Domain\Model\SchemaSyncResult;

/**
 * One stage of the orchestrator's sweep.
 *
 * Exactly one of `report` / `syncResult` / `scaffoldReport` is non-null:
 *   - `report`         is set for the patch stages (pre-patches / apply-patches / post-patches)
 *   - `syncResult`     is set for the orm-sync stage
 *   - `scaffoldReport` is set for the scaffold-sync stage (uw-3)
 */
final readonly class OrchestratorStage
{
    public function __construct(
        public string $name,
        public ?RunReport $report,
        public ?SchemaSyncResult $syncResult,
        public ?ScaffoldSyncReport $scaffoldReport = null,
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
        return true;
    }
}
