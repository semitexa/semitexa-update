<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Update\Domain\Model\SkippedPatch;

use Semitexa\Update\Domain\Model\RunReport;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Update\Context\DataPatchContext;
use Semitexa\Update\Domain\Contract\DataPatchInterface;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Discovery\DiscoveredPatch;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Update\Exception\PatchExecutionException;
use Semitexa\Update\Exception\UpdateException;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Domain\Model\Plan;
use Semitexa\Update\Application\Service\SchemaCompatibilityChecker;
use Throwable;

/**
 * Orchestrates the data-patch half of the update lifecycle.
 *
 * Contract:
 *   - plan() returns a Plan; no side effects except ensuring the journal table exists.
 *   - run() applies pending patches in phase/topological order; stops on first failure.
 *   - Each patch is gated by SchemaCompatibilityChecker; a gated patch is skipped (not failed).
 *   - The journal row transitions pending -> applied on success, pending -> failed on error.
 *   - Patch bodies are responsible for their own transactions and idempotency.
 *   - Schema migrations are NOT this runner's job: ORM is the only owner of structural
 *     database changes (`orm:diff` / `orm:sync`). The DDL keyword guard in
 *     DataPatchContext rejects patches that try to issue DDL.
 */
final class UpdateRunner
{
    public function __construct(
        private readonly DataPatchDiscovery $discovery,
        private readonly DagBuilder $dagBuilder,
        private readonly JournalRepository $journal,
        private readonly DatabaseAdapterInterface $adapter,
        private readonly SchemaCompatibilityChecker $compatibilityChecker,
        private readonly ?string $semitexaVersion = null,
    ) {
    }

    public function plan(): Plan
    {
        $this->journal->ensureSchema();
        $patches = $this->discovery->discover();
        $entries = $this->journal->findAllByIdentity();
        return $this->dagBuilder->build($patches, $entries);
    }

    public function run(?Plan $plan = null): RunReport
    {
        return $this->runPhases($plan, UpdatePhase::order());
    }

    /**
     * Run only the patches whose phase is in $phases. Used by the orchestrator
     * to interleave Pre/Apply/Post around the ORM schema-sync step.
     *
     * @param list<UpdatePhase> $phases
     */
    public function runPhases(?Plan $plan, array $phases): RunReport
    {
        $plan ??= $this->plan();
        $allowed = [];
        foreach ($phases as $phase) {
            $allowed[$phase->value] = true;
        }

        $startedAt = $this->now();
        $startMicro = microtime(true);

        $applied = [];
        $skipped = [];
        $failedIdentity = null;
        $failedError = null;

        foreach ($plan->pendingOrdered() as $patch) {
            if (!isset($allowed[$patch->phase->value])) {
                continue;
            }

            $compat = $this->compatibilityChecker->check($patch, $this->semitexaVersion);
            if (!$compat->compatible) {
                $skipped[] = new SkippedPatch($patch->identity, $compat->reasons);
                continue;
            }

            try {
                $this->executePatch($patch);
                $applied[] = $patch->identity;
            } catch (PatchExecutionException $e) {
                $failedIdentity = $patch->identity;
                $failedError = $e->getPrevious()?->getMessage() ?? $e->getMessage();
                break;
            }
        }

        $completedAt = $this->now();
        $durationMs = (int) round((microtime(true) - $startMicro) * 1000);

        return new RunReport(
            applied: $applied,
            skipped: $skipped,
            failedIdentity: $failedIdentity,
            failedError: $failedError,
            startedAt: $startedAt,
            completedAt: $completedAt,
            durationMs: $durationMs,
        );
    }

    private function executePatch(DiscoveredPatch $patch): void
    {
        $this->journal->markPending(
            module: $patch->module,
            patchId: $patch->id,
            patchFqcn: $patch->fqcn,
            phase: $patch->phase,
            checksum: $patch->checksum,
            semitexaVersion: $this->semitexaVersion,
        );

        $startMicro = microtime(true);
        try {
            $instance = $this->instantiate($patch->fqcn);
            $instance->apply($this->makeContext());
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startMicro) * 1000);
            $this->journal->markFailed($patch->module, $patch->id, $this->formatError($e), $durationMs);
            throw new PatchExecutionException($patch->identity, $patch->fqcn, $e);
        }

        $durationMs = (int) round((microtime(true) - $startMicro) * 1000);
        $this->journal->markApplied(
            module: $patch->module,
            patchId: $patch->id,
            durationMs: $durationMs,
            appliedDbStateHash: null,
        );
    }

    /**
     * @param class-string $fqcn
     */
    private function instantiate(string $fqcn): DataPatchInterface
    {
        if (!class_exists($fqcn)) {
            throw new UpdateException("Patch class {$fqcn} cannot be loaded.");
        }
        /** @var object $instance */
        $instance = new $fqcn();
        if (!$instance instanceof DataPatchInterface) {
            throw new UpdateException(sprintf(
                'Patch %s must implement %s.',
                $fqcn,
                DataPatchInterface::class,
            ));
        }
        return $instance;
    }

    private function makeContext(): DataPatchContext
    {
        return new DataPatchContext($this->adapter);
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
