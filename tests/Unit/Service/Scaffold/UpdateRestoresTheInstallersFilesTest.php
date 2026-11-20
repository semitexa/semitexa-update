<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Installer\Application\Service\Scaffold as InstallerScaffold;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Application\Service\LiveSchemaInspector;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldSyncEngine;
use Semitexa\Update\Application\Service\SchemaCompatibilityChecker;
use Semitexa\Update\Application\Service\UpdateOrchestrator;
use Semitexa\Update\Application\Service\UpdateRunner;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Domain\Contract\OrmMigrationGatewayInterface;
use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport;
use Semitexa\Update\Domain\Model\SchemaSyncResult;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;

/**
 * A project's scaffold, synced from the real sources `bin/semitexa update`
 * reads since step 2 of one source for bin/semitexa: semitexa/installer as
 * Composer installed it, and this package's own agent docs.
 *
 * Unlike the tests with fixture manifests, this one runs on the shipped files
 * and manifests, so a source the factory names wrongly, or a manifest that does
 * not describe its files, fails here.
 */
final class UpdateRestoresTheInstallersFilesTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/semitexa-restores-installer-files-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/bin', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    public function testAMissingRuntimeFileComesFromTheInstallerAndAnEditedOneIsKept(): void
    {
        $installer = InstallerScaffold::directory();
        // bin/semitexa as the installer ships it now: nothing to do.
        copy($installer . '/bin/semitexa', $this->project . '/bin/semitexa');
        // docker/e2e-runner/Dockerfile: missing, as in a project that deleted it.
        // .env.default: edited by the project.
        file_put_contents($this->project . '/.env.default', "APP_NAME=mine\n");

        $report = $this->sync();

        self::assertTrue($report->isSuccess(), implode('; ', $report->integrityErrors));
        self::assertSame(ScaffoldSyncOutcome::NoOp, $report->resultByPath('bin/semitexa')?->outcome);
        self::assertFileEquals($installer . '/docker/e2e-runner/Dockerfile', $this->project . '/docker/e2e-runner/Dockerfile', 'the deleted file comes back from semitexa/installer');
        self::assertSame("APP_NAME=mine\n", file_get_contents($this->project . '/.env.default'), "the project's edit is kept");
        // A runtime file the project edited is left for a person to merge.
        self::assertSame(ScaffoldSyncOutcome::ManualReview, $report->resultByPath('.env.default')?->outcome);
    }

    public function testTheAgentDocsStillComeFromThisPackage(): void
    {
        copy(InstallerScaffold::directory() . '/bin/semitexa', $this->project . '/bin/semitexa');
        // A project that edited its manual gets the new one beside it, as .new;
        // one that deleted it meant it, and is left without.
        file_put_contents($this->project . '/AGENTS.md', "# our own manual\n");

        $report = $this->sync();

        self::assertTrue($report->isSuccess(), implode('; ', $report->integrityErrors));
        self::assertSame("# our own manual\n", file_get_contents($this->project . '/AGENTS.md'));
        self::assertFileEquals(\dirname(__DIR__, 4) . '/resources/scaffold/AGENTS.md', $this->project . '/AGENTS.md.new', 'the new manual comes from this package');
        self::assertFileDoesNotExist($this->project . '/CLAUDE.md', 'a deleted agent doc is not brought back');
    }

    private function sync(): ScaffoldSyncReport
    {
        $hasher = new ScaffoldHasher();
        $db = new SqliteAdapter('sqlite::memory:');
        $orchestrator = new UpdateOrchestrator(
            runner: new UpdateRunner(
                discovery: new DataPatchDiscovery(new ClassDiscovery()),
                dagBuilder: new DagBuilder(),
                journal: new JournalRepository($db),
                adapter: $db,
                compatibilityChecker: new SchemaCompatibilityChecker(new LiveSchemaInspector($db)),
                semitexaVersion: null,
            ),
            migrationGateway: new class implements OrmMigrationGatewayInterface {
                public function inspect(string $connection): SchemaSyncStatus
                {
                    return new SchemaSyncStatus(inSync: true, pendingOperations: 0, destructiveOperations: 0, summary: 'in sync (stub)');
                }

                public function synchronize(string $connection, bool $allowDestructive = false, bool $dryRun = false): SchemaSyncResult
                {
                    return new SchemaSyncResult(executedOperations: 0, skippedDestructive: 0, dryRun: $dryRun, summary: 'no-op (stub)');
                }
            },
            connection: 'default',
            scaffoldLoader: new ScaffoldManifestLoader(),
            scaffoldClassifier: new ScaffoldFileClassifier($hasher),
            scaffoldEngine: new ScaffoldSyncEngine($hasher),
            projectRoot: $this->project,
            scaffoldSources: UpdateRunnerFactory::scaffoldSources(\dirname(__DIR__, 4)),
        );

        foreach ($orchestrator->run(dryRun: false) as $stage) {
            if ($stage->name === 'scaffold-sync' && $stage->scaffoldReport !== null) {
                return $stage->scaffoldReport;
            }
        }
        self::fail('the run had no scaffold-sync stage');
    }
}
