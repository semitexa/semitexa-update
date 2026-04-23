<?php

declare(strict_types=1);

namespace Semitexa\Update\Planner;

use Semitexa\Update\Discovery\DiscoveredStep;
use Semitexa\Update\Enum\StepStatus;
use Semitexa\Update\Enum\UpdatePhase;
use Semitexa\Update\Exception\DagCycleException;
use Semitexa\Update\Exception\UpdateException;
use Semitexa\Update\Journal\JournalEntry;

/**
 * Validates dependencies, detects cycles, and emits a Plan with per-phase
 * topologically-sorted lists of pending and applied steps.
 *
 * Cross-phase dependencies are permitted only forward (PreDeploy -> Deploy
 * -> Finalize); backward cross-phase deps are a phase-ordering violation,
 * not a DAG error, and are rejected up front.
 */
final class DagBuilder
{
    /**
     * @param list<DiscoveredStep>        $steps
     * @param array<string, JournalEntry> $journalByFqcn
     */
    public function build(array $steps, array $journalByFqcn): Plan
    {
        $byFqcn = [];
        foreach ($steps as $step) {
            $byFqcn[$step->fqcn] = $step;
        }

        $this->validateDependencies($byFqcn);

        $pendingByPhase = [];
        $appliedByPhase = [];
        foreach (UpdatePhase::order() as $phase) {
            $phaseSteps = array_values(array_filter(
                $steps,
                static fn (DiscoveredStep $s): bool => $s->phase === $phase,
            ));
            $ordered = $this->topoSortPhase($phaseSteps);

            $pending = [];
            $applied = [];
            foreach ($ordered as $step) {
                $entry = $journalByFqcn[$step->fqcn] ?? null;
                if ($entry !== null && $entry->status === StepStatus::Applied) {
                    $applied[] = $step;
                } else {
                    $pending[] = $step;
                }
            }

            $pendingByPhase[$phase->value] = $pending;
            $appliedByPhase[$phase->value] = $applied;
        }

        return new Plan($pendingByPhase, $appliedByPhase, $journalByFqcn);
    }

    /**
     * @param array<class-string, DiscoveredStep> $byFqcn
     */
    private function validateDependencies(array $byFqcn): void
    {
        $phaseOrder = [];
        foreach (UpdatePhase::order() as $i => $phase) {
            $phaseOrder[$phase->value] = $i;
        }

        foreach ($byFqcn as $step) {
            foreach ($step->dependencies as $dep) {
                if (!isset($byFqcn[$dep])) {
                    throw new UpdateException(sprintf(
                        'Step %s depends on unknown step %s. Either the dependency is missing #[AsUpdateStep], or the package is not installed.',
                        $step->fqcn,
                        $dep,
                    ));
                }

                $depPhase = $byFqcn[$dep]->phase;
                if ($phaseOrder[$depPhase->value] > $phaseOrder[$step->phase->value]) {
                    throw new UpdateException(sprintf(
                        'Step %s (phase=%s) depends on %s (phase=%s), which is a later phase. Dependencies must flow forward in phase order.',
                        $step->fqcn,
                        $step->phase->value,
                        $dep,
                        $depPhase->value,
                    ));
                }
            }
        }
    }

    /**
     * Kahn's algorithm using only intra-phase edges. Ties broken by FQCN for
     * deterministic output. A cycle is detected when the queue empties before
     * all nodes are processed; cycle membership is surfaced via DFS for a
     * readable error message.
     *
     * @param  list<DiscoveredStep> $phaseSteps
     * @return list<DiscoveredStep>
     */
    private function topoSortPhase(array $phaseSteps): array
    {
        if ($phaseSteps === []) {
            return [];
        }

        $inPhase = [];
        foreach ($phaseSteps as $step) {
            $inPhase[$step->fqcn] = $step;
        }

        /** @var array<string, array<string, true>> $edges dep -> set of dependents */
        $edges = [];
        /** @var array<string, int> $inDegree */
        $inDegree = [];
        foreach ($inPhase as $fqcn => $step) {
            $inDegree[$fqcn] = 0;
            $edges[$fqcn] = [];
        }
        foreach ($inPhase as $step) {
            foreach ($step->dependencies as $dep) {
                if (!isset($inPhase[$dep])) {
                    // Cross-phase dep; already enforced by phase order.
                    continue;
                }
                $edges[$dep][$step->fqcn] = true;
                $inDegree[$step->fqcn]++;
            }
        }

        $ready = [];
        foreach ($inDegree as $fqcn => $deg) {
            if ($deg === 0) {
                $ready[] = $fqcn;
            }
        }
        sort($ready);

        $ordered = [];
        while ($ready !== []) {
            $fqcn = array_shift($ready);
            $ordered[] = $inPhase[$fqcn];

            $newlyReady = [];
            foreach (array_keys($edges[$fqcn]) as $dependent) {
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
     * @param array<class-string, DiscoveredStep> $inPhase
     * @return list<class-string>
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
