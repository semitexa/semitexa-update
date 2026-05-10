<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Update\Discovery\DiscoveredPatch;
use Semitexa\Update\Domain\Enum\PatchStatus;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Update\Exception\DagCycleException;
use Semitexa\Update\Exception\UpdateException;
use Semitexa\Update\Domain\Model\JournalEntry;
use Semitexa\Update\Domain\Model\Plan;

/**
 * Validates dependencies, detects cycles, and emits a Plan with per-phase
 * topologically-sorted lists of pending and applied patches.
 *
 * Patches identify each other by `(module, id)` rendered as "module:id". Cross-phase
 * dependencies are permitted only forward (Pre -> Apply -> Post); backward cross-phase
 * deps are a phase-ordering violation, not a DAG error, and are rejected up front.
 */
final class DagBuilder
{
    /**
     * @param list<DiscoveredPatch>       $patches
     * @param array<string, JournalEntry> $journalByIdentity
     */
    public function build(array $patches, array $journalByIdentity): Plan
    {
        $byIdentity = [];
        foreach ($patches as $patch) {
            $byIdentity[$patch->identity] = $patch;
        }

        $this->validateDependencies($byIdentity);

        $pendingByPhase = [];
        $appliedByPhase = [];
        foreach (UpdatePhase::order() as $phase) {
            $phasePatches = array_values(array_filter(
                $patches,
                static fn (DiscoveredPatch $p): bool => $p->phase === $phase,
            ));
            $ordered = $this->topoSortPhase($phasePatches);

            $pending = [];
            $applied = [];
            foreach ($ordered as $patch) {
                $entry = $journalByIdentity[$patch->identity] ?? null;
                if ($entry !== null && $entry->status === PatchStatus::Applied) {
                    $applied[] = $patch;
                } else {
                    $pending[] = $patch;
                }
            }

            $pendingByPhase[$phase->value] = $pending;
            $appliedByPhase[$phase->value] = $applied;
        }

        return new Plan($pendingByPhase, $appliedByPhase, $journalByIdentity);
    }

    /**
     * @param array<string, DiscoveredPatch> $byIdentity
     */
    private function validateDependencies(array $byIdentity): void
    {
        $phaseOrder = [];
        foreach (UpdatePhase::order() as $i => $phase) {
            $phaseOrder[$phase->value] = $i;
        }

        foreach ($byIdentity as $patch) {
            foreach ($patch->dependencies as $dep) {
                if (!isset($byIdentity[$dep])) {
                    throw new UpdateException(sprintf(
                        'Patch %s depends on unknown patch %s. Either the dependency is missing #[AsDataPatch], or the package is not installed.',
                        $patch->identity,
                        $dep,
                    ));
                }

                $depPhase = $byIdentity[$dep]->phase;
                if ($phaseOrder[$depPhase->value] > $phaseOrder[$patch->phase->value]) {
                    throw new UpdateException(sprintf(
                        'Patch %s (phase=%s) depends on %s (phase=%s), which is a later phase. Dependencies must flow forward in phase order.',
                        $patch->identity,
                        $patch->phase->value,
                        $dep,
                        $depPhase->value,
                    ));
                }
            }
        }
    }

    /**
     * Kahn's algorithm using only intra-phase edges. Ties broken by identity for
     * deterministic output. A cycle is detected when the queue empties before
     * all nodes are processed; cycle membership is surfaced via DFS for a
     * readable error message.
     *
     * @param  list<DiscoveredPatch> $phasePatches
     * @return list<DiscoveredPatch>
     */
    private function topoSortPhase(array $phasePatches): array
    {
        if ($phasePatches === []) {
            return [];
        }

        $inPhase = [];
        foreach ($phasePatches as $patch) {
            $inPhase[$patch->identity] = $patch;
        }

        /** @var array<string, array<string, true>> $edges dep -> set of dependents */
        $edges = [];
        /** @var array<string, int> $inDegree */
        $inDegree = [];
        foreach ($inPhase as $identity => $patch) {
            $inDegree[$identity] = 0;
            $edges[$identity] = [];
        }
        foreach ($inPhase as $patch) {
            foreach ($patch->dependencies as $dep) {
                if (!isset($inPhase[$dep])) {
                    // Cross-phase dep; already enforced by phase order.
                    continue;
                }
                $edges[$dep][$patch->identity] = true;
                $inDegree[$patch->identity]++;
            }
        }

        $ready = [];
        foreach ($inDegree as $identity => $deg) {
            if ($deg === 0) {
                $ready[] = $identity;
            }
        }
        sort($ready);

        $ordered = [];
        while ($ready !== []) {
            $identity = array_shift($ready);
            $ordered[] = $inPhase[$identity];

            $newlyReady = [];
            foreach (array_keys($edges[$identity]) as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $newlyReady[] = $dependent;
                }
            }
            if ($newlyReady !== []) {
                sort($newlyReady);
                $ready = array_merge($ready, $newlyReady);
                sort($ready);
            }
        }

        if (count($ordered) !== count($inPhase)) {
            throw new DagCycleException($this->findCycle($inPhase));
        }

        return $ordered;
    }

    /**
     * DFS-based cycle extraction — only runs when a cycle has already been proven to exist.
     *
     * @param array<string, DiscoveredPatch> $inPhase
     * @return list<string>
     */
    private function findCycle(array $inPhase): array
    {
        $state = [];               // 0=unvisited, 1=on-stack, 2=done
        $stack = [];
        $foundCycle = null;

        $visit = function (string $node) use (&$visit, &$state, &$stack, $inPhase, &$foundCycle): void {
            if ($foundCycle !== null) {
                return;
            }
            $state[$node] = 1;
            $stack[] = $node;

            foreach ($inPhase[$node]->dependencies as $dep) {
                if (!isset($inPhase[$dep])) {
                    continue;
                }
                $depState = $state[$dep] ?? 0;
                if ($depState === 1) {
                    $start = array_search($dep, $stack, true);
                    if ($start !== false) {
                        $foundCycle = array_values(array_slice($stack, $start));
                        $foundCycle[] = $dep;
                    }
                    return;
                }
                if ($depState === 0) {
                    $visit($dep);
                    if ($foundCycle !== null) {
                        return;
                    }
                }
            }

            array_pop($stack);
            $state[$node] = 2;
        };

        foreach (array_keys($inPhase) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit($node);
                if ($foundCycle !== null) {
                    return $foundCycle;
                }
            }
        }

        return array_keys($inPhase);
    }
}
