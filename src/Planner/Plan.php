<?php

declare(strict_types=1);

namespace Semitexa\Update\Planner;

use Semitexa\Update\Discovery\DiscoveredStep;
use Semitexa\Update\Enum\UpdatePhase;
use Semitexa\Update\Journal\JournalEntry;

/**
 * Output of DagBuilder. Holds pending and applied steps partitioned by phase,
 * already in a legal execution order within each phase.
 */
final readonly class Plan
{
    /**
     * @param array<string, list<DiscoveredStep>> $pendingByPhase   Keyed by UpdatePhase->value.
     * @param array<string, list<DiscoveredStep>> $appliedByPhase   Keyed by UpdatePhase->value.
     * @param array<string, JournalEntry>         $journalByFqcn    For reporting / status.
     */
    public function __construct(
        public array $pendingByPhase,
        public array $appliedByPhase,
        public array $journalByFqcn,
    ) {
    }

    /**
     * @return list<DiscoveredStep>
     */
    public function pendingOrdered(): array
    {
        $ordered = [];
        foreach (UpdatePhase::order() as $phase) {
            foreach ($this->pendingByPhase[$phase->value] ?? [] as $step) {
                $ordered[] = $step;
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
