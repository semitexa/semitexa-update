<?php

declare(strict_types=1);

namespace Semitexa\Update\Runner;

final readonly class RunReport
{
    /**
     * @param list<class-string> $applied    Steps that ran to success this invocation.
     * @param class-string|null  $failedFqcn First step that failed, or null on success.
     * @param string|null        $failedError Error message from the failed step.
     */
    public function __construct(
        public array $applied,
        public ?string $failedFqcn,
        public ?string $failedError,
        public string $startedAt,
        public string $completedAt,
        public int $durationMs,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->failedFqcn === null;
    }
}
