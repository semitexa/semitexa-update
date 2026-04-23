<?php

declare(strict_types=1);

namespace Semitexa\Update\Runner;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Update\Context\UpdateContext;
use Semitexa\Update\Contract\UpdateStepInterface;
use Semitexa\Update\Discovery\DiscoveredStep;
use Semitexa\Update\Discovery\UpdateStepDiscovery;
use Semitexa\Update\Exception\StepExecutionException;
use Semitexa\Update\Exception\UpdateException;
use Semitexa\Update\Journal\JournalRepository;
use Semitexa\Update\Planner\DagBuilder;
use Semitexa\Update\Planner\Plan;
use Throwable;

/**
 * Top-level orchestrator. Composes discovery + DAG + journal + execution.
 *
 * V1 contract:
 *   - plan() returns a Plan; no side effects except ensuring the journal table exists.
 *   - run() applies pending steps in phase/topological order; stops on first failure.
 *   - The journal row transitions pending -> applied on success, pending -> failed on error.
 *   - Step bodies are responsible for their own transactions and idempotency.
 */
final class UpdateRunner
{
    public function __construct(
        private readonly UpdateStepDiscovery $discovery,
        private readonly DagBuilder $dagBuilder,
        private readonly JournalRepository $journal,
        private readonly DatabaseAdapterInterface $adapter,
    ) {
    }

    public function plan(): Plan
    {
        $this->journal->ensureSchema();
        $steps = $this->discovery->discover();
        $entries = $this->journal->findAllByFqcn();
        return $this->dagBuilder->build($steps, $entries);
    }

    public function run(?Plan $plan = null): RunReport
    {
        $plan ??= $this->plan();

        $startedAt = $this->now();
        $startMicro = microtime(true);

        $applied = [];
        $failedFqcn = null;
        $failedError = null;

        foreach ($plan->pendingOrdered() as $step) {
            try {
                $this->executeStep($step);
                $applied[] = $step->fqcn;
            } catch (StepExecutionException $e) {
                $failedFqcn = $step->fqcn;
                $failedError = $e->getPrevious()?->getMessage() ?? $e->getMessage();
                break;
            }
        }

        $completedAt = $this->now();
        $durationMs = (int) round((microtime(true) - $startMicro) * 1000);

        return new RunReport(
            applied: $applied,
            failedFqcn: $failedFqcn,
            failedError: $failedError,
            startedAt: $startedAt,
            completedAt: $completedAt,
            durationMs: $durationMs,
        );
    }

    private function executeStep(DiscoveredStep $step): void
    {
        $this->journal->markPending($step->fqcn, $step->phase, $step->checksum);

        $startMicro = microtime(true);
        try {
            $instance = $this->instantiate($step->fqcn);
            $instance->apply($this->makeContext());
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startMicro) * 1000);
            $this->journal->markFailed($step->fqcn, $this->formatError($e), $durationMs);
            throw new StepExecutionException($step->fqcn, $e);
        }

        $durationMs = (int) round((microtime(true) - $startMicro) * 1000);
        $this->journal->markApplied($step->fqcn, $durationMs);
    }

    /**
     * @param class-string $fqcn
     */
    private function instantiate(string $fqcn): UpdateStepInterface
    {
        if (!class_exists($fqcn)) {
            throw new UpdateException("Step class {$fqcn} cannot be loaded.");
        }
        /** @var object $instance */
        $instance = new $fqcn();
        if (!$instance instanceof UpdateStepInterface) {
            throw new UpdateException(sprintf(
                'Step %s must implement %s.',
                $fqcn,
                UpdateStepInterface::class,
            ));
        }
        return $instance;
    }

    private function makeContext(): UpdateContext
    {
        return new UpdateContext($this->adapter);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function formatError(Throwable $e): string
    {
        return sprintf('[%s] %s', $e::class, $e->getMessage());
    }
}
