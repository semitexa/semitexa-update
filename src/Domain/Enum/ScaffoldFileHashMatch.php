<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * Result of asking a {@see \Semitexa\Update\Domain\Model\Scaffold\ScaffoldFileEntry}
 * "have you seen this hash before?". The future sync engine uses this to
 * decide whether a downstream-project file:
 *
 *   - matches the current scaffold (no action)
 *   - matches a known prior scaffold (safe to auto-update with backup)
 *   - is locally modified (write a .new conflict report; never overwrite)
 *
 * "Unknown" deliberately covers both genuinely-customized files and files
 * from scaffold versions that were never recorded in previous_sha256.
 */
enum ScaffoldFileHashMatch: string
{
    case Current = 'current';
    case KnownPrevious = 'known_previous';
    case Unknown = 'unknown';
}
