<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;

/**
 * One planned action against a single scaffold-managed project file.
 *
 *  - `oldHash`         is the project file's current SHA-256, or null if the
 *                      file does not exist.
 *  - `newHash`         is the scaffold manifest's current_sha256 for this path.
 *  - `backupPath`      is set iff `action === Replace` — the engine will write
 *                      the existing file's contents here before overwriting.
 *  - `newFilePath`     is set iff `action === WriteNew` — where the engine
 *                      will write the current scaffold content. Usually
 *                      `<path>.new`, occasionally `<path>.new.<timestamp>` when
 *                      a prior `.new` exists with operator edits.
 */
final readonly class ScaffoldSyncPlanEntry
{
    public function __construct(
        public string $path,
        public ScaffoldSyncStatus $status,
        public ScaffoldSyncAction $action,
        public ?string $oldHash,
        public string $newHash,
        public ?string $backupPath,
        public ?string $newFilePath,
        public bool $preserveExecutable,
        public bool $critical,
        public string $reason,
    ) {
    }
}
