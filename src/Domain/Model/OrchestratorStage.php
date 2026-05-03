<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

use Semitexa\Update\Domain\Model\SchemaSyncResult;

/**
 * One stage of the orchestrator's Pre / ORM-sync / Apply / Post sweep.
 *
 * Exactly one of `report` or `syncResult` is non-null:
 *   - `report` is set for the patch stages (pre-patches / apply-patches / post-patches)
 *   - `syncResult` is set for the orm-sync stage
 */
final readonly class OrchestratorStage
{
    public function __construct(
        public string $name,
        public ?RunReport $report,
        public ?SchemaSyncResult $syncResult,
    ) {
    }

    public function isSuccess(): bool
    {
        if ($this->report !== null) {
            return $this->report->isSuccess();
        }
        return true;
    }
}
