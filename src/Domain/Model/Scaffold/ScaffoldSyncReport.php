<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Scaffold;

use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;

/**
 * Aggregate execution report. Mirrors {@see ScaffoldSyncPlan} with outcomes
 * populated; rendered by the future UpdateCommand summary.
 */
final readonly class ScaffoldSyncReport
{
    /**
     * @param list<ScaffoldSyncResult> $results
     * @param list<string>             $integrityErrors
     */
    public function __construct(
        public array $results,
        public bool $dryRun,
        public array $integrityErrors,
    ) {
    }

    public function isSuccess(): bool
    {
        if ($this->integrityErrors !== []) {
            return false;
        }
        foreach ($this->results as $result) {
            if ($result->outcome === ScaffoldSyncOutcome::Failed) {
                return false;
            }
        }
        return true;
    }

    public function resultByPath(string $path): ?ScaffoldSyncResult
    {
        foreach ($this->results as $result) {
            if ($result->path === $path) {
                return $result;
            }
        }
        return null;
    }

    /**
     * @return list<ScaffoldSyncResult>
     */
    public function actionableResults(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (ScaffoldSyncResult $r): bool => $r->outcome !== ScaffoldSyncOutcome::NoOp,
        ));
    }
}
