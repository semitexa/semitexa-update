<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

/**
 * Read-only snapshot of the ORM schema's relationship to the live database.
 *
 * `inSync = true` means orm:sync would currently be a no-op. `pendingOperations`
 * is the count produced by the ORM's diff; it lets Update render a one-line
 * summary without knowing what the diff contains.
 */
final readonly class SchemaSyncStatus
{
    public function __construct(
        public bool $inSync,
        public int $pendingOperations,
        public int $destructiveOperations,
        public string $summary,
    ) {
    }
}
