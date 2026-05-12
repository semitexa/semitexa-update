<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Composer;

use Semitexa\Update\Domain\Enum\ComposerUpdateOutcome;

/**
 * Result of executing the Composer-update phase.
 *
 *   $bumpedPackages         — package => [from, to] for every pin actually
 *                             changed in composer.json by the runner.
 *   $installedBefore        — installed version of semitexa/update BEFORE
 *                             the composer command ran (from installed.json).
 *   $installedAfter         — same, AFTER. When these differ the orchestrator
 *                             stops with UpdaterChanged.
 *   $composerExitCode       — 0 means composer succeeded.
 *   $composerOutput         — tail of composer stdout/stderr to surface in
 *                             the operator-facing summary.
 *   $message                — human summary for the report.
 */
final readonly class ComposerUpdateResult
{
    /**
     * @param array<string, array{from: ?string, to: string}> $bumpedPackages
     */
    public function __construct(
        public ComposerUpdateOutcome $outcome,
        public array $bumpedPackages,
        public ?string $installedBefore,
        public ?string $installedAfter,
        public int $composerExitCode,
        public string $composerOutput,
        public string $message,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->outcome !== ComposerUpdateOutcome::Failed;
    }
}
