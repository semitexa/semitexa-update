<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldSyncEngine;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Domain\Contract\OrmMigrationGatewayInterface;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Application\Service\LiveSchemaInspector;
use Semitexa\Update\Application\Service\SchemaCompatibilityChecker;

/**
 * Builds the data-patch runner and the full update orchestrator for a given
 * connection. Console commands use this factory so wiring lives in one place.
 *
 * The framework version gate is opt-in for now: callers may pass the current
 * Semitexa version explicitly, and `null` disables min/max-Semitexa checks.
 */
#[AsService]
class UpdateRunnerFactory
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
        $projectRoot = ProjectRoot::get();
        $hasher = new ScaffoldHasher();
        $packageRoot = $this->packageRoot();

        return new UpdateOrchestrator(
            runner: $this->create($connection, $semitexaVersion),
            migrationGateway: $this->migrationGateway,
            connection: $connection,
            driftInspector: new PackageDriftInspector(),
            scaffoldLoader: new ScaffoldManifestLoader(),
            scaffoldClassifier: new ScaffoldFileClassifier($hasher),
            scaffoldEngine: new ScaffoldSyncEngine($hasher),
            projectRoot: $projectRoot,
            scaffoldRoot: $packageRoot . '/resources/scaffold',
            manifestPath: $packageRoot . '/resources/scaffold-manifest.json',
        );
    }

    /**
     * Returns the on-disk root of the `semitexa/update` package itself.
     * Works whether the package is checked out at `packages/semitexa-update`
     * (dev monorepo) or installed at `vendor/semitexa/update` (downstream).
     */
    private function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
