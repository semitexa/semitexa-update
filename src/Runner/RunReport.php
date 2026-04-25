<?php

declare(strict_types=1);

namespace Semitexa\Update\Runner;

/**
 * Outcome of a single UpdateRunner::run() invocation.
 *
 * Patches are reported by identity ("module:id") rather than FQCN — identity is the
 * stable key tracked in the journal.
 */
final readonly class RunReport
{
    /**
     * @param list<string>      $applied        Identities of patches applied this invocation.
     * @param list<SkippedPatch>$skipped        Patches gated out by the schema-compatibility check.
     * @param string|null       $failedIdentity First patch that failed, or null on success.
     * @param string|null       $failedError    Error message from the failed patch.
     */
    public function __construct(
        public array $applied,
        public array $skipped,
        public ?string $failedIdentity,
        public ?string $failedError,
        public string $startedAt,
        public string $completedAt,
        public int $durationMs,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->failedIdentity === null;
    }
}
