<?php

declare(strict_types=1);

namespace Semitexa\Update\Journal;

use Semitexa\Update\Enum\StepStatus;
use Semitexa\Update\Enum\UpdatePhase;

final readonly class JournalEntry
{
    public function __construct(
        public string $id,
        public string $stepFqcn,
        public UpdatePhase $phase,
        public string $checksum,
        public StepStatus $status,
        public string $startedAt,
        public ?string $completedAt,
        public ?int $durationMs,
        public ?string $error,
    ) {
    }
}
