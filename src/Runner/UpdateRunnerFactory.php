<?php

declare(strict_types=1);

namespace Semitexa\Update\Runner;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Journal\JournalRepository;
use Semitexa\Update\Migration\OrmMigrationGatewayInterface;
use Semitexa\Update\Planner\DagBuilder;
use Semitexa\Update\Schema\LiveSchemaInspector;
use Semitexa\Update\Schema\SchemaCompatibilityChecker;

/**
 * Builds the data-patch runner and the full update orchestrator for a given
 * connection. Console commands use this factory so wiring lives in one place.
 *
 * The framework version gate is opt-in for now: callers may pass the current
 * Semitexa version explicitly, and `null` disables min/max-Semitexa checks.
 */
#[AsService]
final class UpdateRunnerFactory
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    #[InjectAsReadonly]
    protected OrmMigrationGatewayInterface $migrationGateway;

    public function create(string $connection = 'default', ?string $semitexaVersion = null): UpdateRunner
    {
        $adapter = $this->connections->manager($connection)->getAdapter();

        return new UpdateRunner(
            discovery: new DataPatchDiscovery($this->classDiscovery),
            dagBuilder: new DagBuilder(),
            journal: new JournalRepository($adapter),
            adapter: $adapter,
            compatibilityChecker: new SchemaCompatibilityChecker(new LiveSchemaInspector($adapter)),
            semitexaVersion: $semitexaVersion,
        );
    }

    public function orchestrator(string $connection = 'default', ?string $semitexaVersion = null): UpdateOrchestrator
    {
        return new UpdateOrchestrator(
            runner: $this->create($connection, $semitexaVersion),
            migrationGateway: $this->migrationGateway,
            connection: $connection,
        );
    }
}
