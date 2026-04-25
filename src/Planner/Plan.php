<?php

declare(strict_types=1);

namespace Semitexa\Update\Planner;

use Semitexa\Update\Discovery\DiscoveredPatch;
use Semitexa\Update\Enum\UpdatePhase;
use Semitexa\Update\Journal\JournalEntry;

/**
 * Output of DagBuilder. Holds pending and applied patches partitioned by phase,
 * already in a legal execution order within each phase.
 */
final readonly class Plan
{
    /**
     * @param array<string, list<DiscoveredPatch>> $pendingByPhase   Keyed by UpdatePhase->value.
     * @param array<string, list<DiscoveredPatch>> $appliedByPhase   Keyed by UpdatePhase->value.
     * @param array<string, JournalEntry>          $journalByIdentity Keyed by "module:patch_id".
     */
    public function __construct(
        public array $pendingByPhase,
        public array $appliedByPhase,
        public array $journalByIdentity,
    ) {
    }

    /**
     * @return list<DiscoveredPatch>
     */
    public function pendingOrdered(): array
    {
        $ordered = [];
        foreach (UpdatePhase::order() as $phase) {
            foreach ($this->pendingByPhase[$phase->value] ?? [] as $patch) {
                $ordered[] = $patch;
            }
        }
        return $ordered;
    }

    public function pendingCount(): int
    {
        return count($this->pendingOrdered());
    }

    public function appliedCount(): int
    {
        $n = 0;
        foreach ($this->appliedByPhase as $list) {
            $n += count($list);
        }
        return $n;
    }

    public function isEmpty(): bool
    {
        return $this->pendingCount() === 0;
    }
}
