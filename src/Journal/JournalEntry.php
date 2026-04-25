<?php

declare(strict_types=1);

namespace Semitexa\Update\Journal;

use Semitexa\Update\Enum\PatchStatus;
use Semitexa\Update\Enum\UpdatePhase;

/**
 * One row of the per-database update journal.
 *
 * Identity is `(module, patchId)`. The class FQCN is recorded for forensic value
 * but is NOT the identity — patches may be renamed between releases without the
 * journal re-running them.
 */
final readonly class JournalEntry
{
    public function __construct(
        public string $id,
        public string $module,
        public string $patchId,
        public ?string $patchFqcn,
        public UpdatePhase $phase,
        public string $checksum,
        public PatchStatus $status,
        public ?string $semitexaVersion,
        public ?string $appliedDbStateHash,
        public string $startedAt,
        public ?string $completedAt,
        public ?int $durationMs,
        public ?string $error,
    ) {
    }

    public function identity(): string
    {
        return $this->module . ':' . $this->patchId;
    }
}
