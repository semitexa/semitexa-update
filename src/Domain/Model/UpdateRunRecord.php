<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

use Semitexa\Update\Domain\Enum\RunOutcome;

/**
 * One row of the run journal: a single invocation of the update sweep
 * (`kind = update`) or of the auto-deploy pipeline (`kind = auto-deploy`).
 *
 *   $stages        — ordered stage summaries: list of
 *                    array{name: string, success: bool, ...stage-specific keys}.
 *   $packageDeltas — package => array{from: ?string, to: string} for every
 *                    version pin that actually changed during the run.
 */
final readonly class UpdateRunRecord
{
    /**
     * @param list<array<string, mixed>>                       $stages
     * @param array<string, array{from: ?string, to: string}>  $packageDeltas
     */
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $actor,
        public ?string $updaterVersion,
        public RunOutcome $outcome,
        public ?string $failedStage,
        public array $stages,
        public array $packageDeltas,
        public int $patchesApplied,
        public ?string $error,
        public string $startedAt,
        public ?string $completedAt,
        public ?int $durationMs,
    ) {
    }
}
