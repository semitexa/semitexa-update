<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * Outcome of the Composer-update phase that now runs first inside
 * `bin/semitexa update`.
 *
 *  - Clean       — no semitexa/* package version changed; the rest of the
 *                  update workflow can continue in this process.
 *  - Updated     — semitexa/* packages changed but the updater package
 *                  (semitexa/update) itself was NOT one of them; safe to
 *                  continue with the in-process orchestrator.
 *  - UpdaterChanged — semitexa/update itself was upgraded; the running
 *                  PHP process is now operating on stale class definitions.
 *                  The orchestrator stops cleanly and tells the operator
 *                  to rerun `bin/semitexa update`.
 *  - Skipped     — operator passed `--no-composer`.
 *  - WouldRun    — dry-run; nothing was executed.
 *  - Failed      — Composer (or pin resolution) exited non-zero; subsequent
 *                  DB-affecting stages do not run.
 */
enum ComposerUpdateOutcome: string
{
    case Clean = 'clean';
    case Updated = 'updated';
    case UpdaterChanged = 'updater_changed';
    case Skipped = 'skipped';
    case WouldRun = 'would_run';
    case Failed = 'failed';

    public function shouldContinueWorkflow(): bool
    {
        return $this === self::Clean
            || $this === self::Updated
            || $this === self::Skipped;
    }
}
