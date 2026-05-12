<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * What the sync engine intends to do with a single scaffold-managed file.
 *
 *  - None     — file is already at the current scaffold (no-op).
 *  - Replace  — file matches a known prior scaffold hash AND auto_update=true.
 *               Engine backs up the existing file, then writes current content.
 *  - Create   — file is missing AND auto_update=true. Engine creates it.
 *  - WriteNew — file is either locally modified, OR matches a prior hash with
 *               auto_update=false, OR is missing with auto_update=false. Engine
 *               writes current scaffold content to <path>.new for the operator
 *               to review/merge. NEVER overwrites the live file.
 */
enum ScaffoldSyncAction: string
{
    case None = 'none';
    case Replace = 'replace';
    case Create = 'create';
    case WriteNew = 'write_new';
}
