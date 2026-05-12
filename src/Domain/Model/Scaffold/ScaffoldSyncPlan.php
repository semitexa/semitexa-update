<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;

/**
 * Aggregate plan produced by the classifier.
 *
 * `integrityErrors` collects mismatches between the scaffold source and the
 * manifest. When non-empty the sync engine refuses to execute any action.
 */
final readonly class ScaffoldSyncPlan
{
    /**
     * @param list<ScaffoldSyncPlanEntry> $entries
     * @param list<string>                $integrityErrors
     */
    public function __construct(
        public array $entries,
        public array $integrityErrors,
    ) {
    }

    public function hasIntegrityFailure(): bool
    {
        return $this->integrityErrors !== [];
    }

    public function entryByPath(string $path): ?ScaffoldSyncPlanEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->path === $path) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @return list<ScaffoldSyncPlanEntry>
     */
    public function actionable(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ScaffoldSyncPlanEntry $e): bool => $e->action !== ScaffoldSyncAction::None,
        ));
    }

    /**
     * @return list<ScaffoldSyncPlanEntry>
     */
    public function conflicts(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ScaffoldSyncPlanEntry $e): bool => $e->status === ScaffoldSyncStatus::LocallyModified,
        ));
    }
}
