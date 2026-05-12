<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;

/**
 * Per-entry execution result. Carries all fields the engine touched so the
 * caller can render a coherent summary without re-deriving anything.
 */
final readonly class ScaffoldSyncResult
{
    public function __construct(
        public string $path,
        public ScaffoldSyncStatus $status,
        public ScaffoldSyncAction $action,
        public ScaffoldSyncOutcome $outcome,
        public ?string $oldHash,
        public string $newHash,
        public ?string $backupPath,
        public ?string $newFilePath,
        public string $message,
    ) {
    }
}
