<?php

declare(strict_types=1);

namespace Semitexa\Update\Runner;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\Update\Discovery\UpdateStepDiscovery;
use Semitexa\Update\Journal\JournalRepository;
use Semitexa\Update\Planner\DagBuilder;

/**
 * Builds a fully-wired UpdateRunner for a given connection. Kept separate so
 * console commands stay focused on I/O and don't repeat wiring boilerplate.
 */
#[AsService]
final class UpdateRunnerFactory
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    public function create(string $connection = 'default'): UpdateRunner
    {
        $adapter = $this->connections->manager($connection)->getAdapter();

        return new UpdateRunner(
            discovery: new UpdateStepDiscovery($this->classDiscovery),
            dagBuilder: new DagBuilder(),
            journal: new JournalRepository($adapter),
            adapter: $adapter,
        );
    }
}
