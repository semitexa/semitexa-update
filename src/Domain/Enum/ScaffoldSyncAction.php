<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * What the sync engine intends to do with a single scaffold-managed file.
 *
 *  - None         — file is already at the current scaffold (no-op).
 *  - Replace      — file matches a known prior scaffold hash AND auto_update=true.
 *                   Engine backs up the existing file, then writes current content.
 *  - Create       — file is missing AND auto_update=true. Engine creates it.
 *  - WriteNew     — operator opted in via --write-scaffold-candidates. Engine
 *                   writes current scaffold content to <path>.new for the
 *                   operator to review/merge. NEVER overwrites the live file.
 *  - ManualReview — default for files that need attention (locally modified,
 *                   or non-auto-update prior/missing) when the operator has NOT
 *                   opted in. Engine performs no filesystem work; the report
 *                   tells the operator what command to run if they want a
 *                   candidate file written.
 */
enum ScaffoldSyncAction: string
{
    case None = 'none';
    case Replace = 'replace';
    case Create = 'create';
    case WriteNew = 'write_new';
    case ManualReview = 'manual_review';
}
