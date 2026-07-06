<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\Update\Application\Service\Composer\ComposerUpdateRunner;
use Semitexa\Update\Application\Service\Composer\InContainerComposerExecutor;
use Semitexa\Update\Application\Service\Composer\PackagistVersionResolver;
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
            composerRunner: new ComposerUpdateRunner(
                executor: new InContainerComposerExecutor(),
                resolver: new PackagistVersionResolver(),
            ),
            runJournal: $this->runJournal($connection),
            actor: self::defaultActor(),
            updaterVersion: self::installedUpdaterVersion(),
            lock: new UpdateLock($projectRoot),
            preflight: new PreflightChecker(
                $this->connections->manager($connection)->getAdapter(),
                $projectRoot,
            ),
            healthChecker: new HealthChecker(),
            healthcheckUrl: self::healthcheckUrl(),
        );
    }

    /**
     * Health URL for the manual sweep's post-update smoke. Dedicated var
     * first, auto-deploy's healthcheck URL as fallback so a configured
     * deployment target gets the smoke in both paths.
     */
    public static function healthcheckUrl(): ?string
    {
        foreach (['SEMITEXA_UPDATE_HEALTHCHECK_URL', 'SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL'] as $var) {
            $value = \Semitexa\Core\Environment::getEnvValue($var, null);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return null;
    }

    /**
     * Run journal for the given connection — the per-run history that
     * `update:history` and the OS "What's new" surface read.
     */
    public function runJournal(string $connection = 'default'): RunJournalRepository
    {
        $adapter = $this->connections->manager($connection)->getAdapter();

        return new RunJournalRepository($adapter);
    }

    /**
     * Who triggered this run. Overridable for non-interactive contexts
     * (systemd auto-deploy exports SEMITEXA_UPDATE_ACTOR).
     */
    public static function defaultActor(): string
    {
        $override = \Semitexa\Core\Environment::getEnvValue('SEMITEXA_UPDATE_ACTOR', null);
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        // get_current_user() reports the script file OWNER, not who runs it —
        // resolve the effective process user, falling back through env.
        $user = '';
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
        }
        if ($user === '') {
            $user = (string) (getenv('USER') ?: getenv('USERNAME') ?: get_current_user());
        }

        return 'cli:' . ($user !== '' ? $user : 'unknown');
    }

    /**
     * Installed version of semitexa/update itself, via Composer's runtime API.
     * Null in a path-repo dev workspace (dev-main) or when the API is absent.
     */
    public static function installedUpdaterVersion(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }

        try {
            if (!\Composer\InstalledVersions::isInstalled('semitexa/update')) {
                return null;
            }
            return \Composer\InstalledVersions::getPrettyVersion('semitexa/update');
        } catch (\Throwable) {
            return null;
        }
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
