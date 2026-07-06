<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * Terminal (and one in-flight) outcome of a whole update run — the unit the
 * run journal tracks. Distinct from {@see PatchStatus}, which tracks a single
 * data patch.
 *
 *   Running — row inserted at run start; still holds it if the process died.
 *   Success — every executed stage succeeded and at least one changed state.
 *   Noop    — every stage succeeded but nothing changed (no pin bumps, no
 *             schema operations, no patches, no scaffold writes).
 *   Aborted — the run stopped itself on purpose (semitexa/update was upgraded
 *             mid-run and in-process code became stale).
 *   Failed  — a stage reported failure or the run threw.
 */
enum RunOutcome: string
{
    case Running = 'running';
    case Success = 'success';
    case Noop = 'noop';
    case Aborted = 'aborted';
    case Failed = 'failed';
}
