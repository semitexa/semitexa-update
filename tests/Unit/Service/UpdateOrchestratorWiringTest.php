<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Application\Service\LiveSchemaInspector;
use Semitexa\Update\Application\Service\PackageDriftInspector;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestBuilder;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldSyncEngine;
use Semitexa\Update\Application\Service\SchemaCompatibilityChecker;
use Semitexa\Update\Application\Service\UpdateOrchestrator;
use Semitexa\Update\Application\Service\UpdateRunner;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Domain\Contract\OrmMigrationGatewayInterface;
use Semitexa\Update\Domain\Enum\PackageDriftStatus;
use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;
use Semitexa\Update\Domain\Model\SchemaSyncResult;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;

/**
 * Integration tests for the uw-4 orchestrator wiring:
 *   - plan() exposes packageDrift + scaffoldPlan when collaborators are configured
 *   - run() emits a scaffold-sync stage that mutates only when not in dry-run
 *   - scaffold integrity failure aborts before any patch / orm stage
 *   - composer is never invoked
 */
final class UpdateOrchestratorWiringTest extends TestCase
{
    private string $projectRoot;
    private string $scaffoldRoot;
    private string $manifestPath;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/semitexa-uw4-' . bin2hex(random_bytes(8));
        $this->projectRoot = $base . '/project';
        $this->scaffoldRoot = $base . '/scaffold';
        $this->manifestPath = $base . '/scaffold-manifest.json';

        mkdir($this->projectRoot . '/bin', 0777, true);
        mkdir($this->scaffoldRoot . '/bin', 0777, true);

        $this->writeScaffoldFixture();
        $this->writeManifest();
        $this->writeComposerFixture();
    }

    protected function tearDown(): void
    {
        $this->rrm(dirname($this->projectRoot));
    }

    public function testPlanIncludesPackageDriftAndScaffoldPlan(): void
    {
        $this->writeProjectFile('bin/semitexa', "#!/bin/sh\necho old\n");

        $report = $this->orchestrator()->plan();

        self::assertNotNull($report->packageDrift);
        self::assertNotNull($report->scaffoldPlan);

        $semitexaUpdate = $report->packageDrift->entryByName('semitexa/update');
        self::assertNotNull($semitexaUpdate);
        self::assertSame(PackageDriftStatus::Clean, $semitexaUpdate->status);

        $bin = $report->scaffoldPlan->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertSame(ScaffoldSyncStatus::KnownPrevious, $bin->status);
        self::assertSame(ScaffoldSyncAction::Replace, $bin->action);
    }

    public function testPlanFieldsAreNullWhenCollaboratorsNotConfigured(): void
    {
        $orchestrator = new UpdateOrchestrator(
            runner: $this->makeRunner(),
            migrationGateway: $this->makeGateway(),
            connection: 'default',
        );

        $report = $orchestrator->plan();

        self::assertNull($report->packageDrift);
        self::assertNull($report->scaffoldPlan);
    }

    public function testRunEmitsScaffoldStageBeforePrePatches(): void
    {
        $this->writeProjectFile('bin/semitexa', "#!/bin/sh\necho old\n");

        $stages = $this->orchestrator()->run(allowDestructive: false, dryRun: false);

        $names = array_map(static fn (\Semitexa\Update\Domain\Model\OrchestratorStage $s) => $s->name, $stages);
        self::assertSame('scaffold-sync', $names[0]);
        self::assertContains('pre-patches', $names);
    }

    public function testDryRunDoesNotMutateScaffoldFilesOrBackup(): void
    {
        $oldBin = "#!/bin/sh\necho old\n";
        $this->writeProjectFile('bin/semitexa', $oldBin);

        $snapshot = $this->fsSnapshot();
        $stages = $this->orchestrator()->run(dryRun: true);

        self::assertSame($snapshot, $this->fsSnapshot(), 'Dry-run must not mutate any file.');
        self::assertSame($oldBin, file_get_contents($this->projectRoot . '/bin/semitexa'));

        $scaffoldStage = $stages[0];
        self::assertNotNull($scaffoldStage->scaffoldReport);
        self::assertTrue($scaffoldStage->scaffoldReport->dryRun);
        $bin = $scaffoldStage->scaffoldReport->resultByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertSame(ScaffoldSyncOutcome::WouldApply, $bin->outcome);
    }

    public function testRealRunReplacesKnownPreviousAndCreatesBackup(): void
    {
        $oldBin = "#!/bin/sh\necho old\n";
        $this->writeProjectFile('bin/semitexa', $oldBin);

        $stages = $this->orchestrator()->run(dryRun: false);

        $scaffoldStage = $stages[0];
        self::assertNotNull($scaffoldStage->scaffoldReport);
        self::assertTrue($scaffoldStage->scaffoldReport->isSuccess());
        $bin = $scaffoldStage->scaffoldReport->resultByPath('bin/semitexa');
        self::assertSame(ScaffoldSyncOutcome::Applied, $bin->outcome);

        // Live file now matches scaffold current, backup contains the old content.
        self::assertSame(
            file_get_contents($this->scaffoldRoot . '/bin/semitexa'),
            file_get_contents($this->projectRoot . '/bin/semitexa'),
        );
        self::assertNotNull($bin->backupPath);
        self::assertSame($oldBin, file_get_contents($this->projectRoot . '/' . $bin->backupPath));
    }

    public function testLocallyModifiedDefaultsToManualReviewWithoutWritingNew(): void
    {
        $operator = "#!/bin/sh\n# operator-customized\necho mine\n";
        $this->writeProjectFile('bin/semitexa', $operator);

        $stages = $this->orchestrator()->run(dryRun: false);
        $bin = $stages[0]->scaffoldReport->resultByPath('bin/semitexa');

        self::assertSame(ScaffoldSyncStatus::LocallyModified, $bin->status);
        self::assertSame(ScaffoldSyncOutcome::ManualReview, $bin->outcome);
        self::assertSame($operator, file_get_contents($this->projectRoot . '/bin/semitexa'));
        self::assertFileDoesNotExist(
            $this->projectRoot . '/bin/semitexa.new',
            'Default real update must NOT create .new for LocallyModified entries — that was the UX bug.',
        );
    }

    public function testLocallyModifiedWritesNewWhenWriteCandidatesFlagIsSet(): void
    {
        $operator = "#!/bin/sh\n# operator-customized\necho mine\n";
        $this->writeProjectFile('bin/semitexa', $operator);

        $stages = $this->orchestrator()->run(dryRun: false, writeCandidates: true);
        $bin = $stages[0]->scaffoldReport->resultByPath('bin/semitexa');

        self::assertSame(ScaffoldSyncOutcome::Applied, $bin->outcome);
        self::assertSame('bin/semitexa.new', $bin->newFilePath);
        self::assertSame($operator, file_get_contents($this->projectRoot . '/bin/semitexa'));
        self::assertFileExists($this->projectRoot . '/bin/semitexa.new');
    }

    public function testScaffoldIntegrityFailureAbortsBeforePatchStages(): void
    {
        $this->writeManifest(corruptCurrentHash: true);
        $this->writeProjectFile('bin/semitexa', "#!/bin/sh\necho old\n");

        $stages = $this->orchestrator()->run(dryRun: false);

        $names = array_map(static fn (\Semitexa\Update\Domain\Model\OrchestratorStage $s) => $s->name, $stages);
        self::assertSame(['scaffold-sync'], $names, 'No patch stages may run after integrity failure.');
        self::assertFalse($stages[0]->isSuccess());
        self::assertNotEmpty($stages[0]->scaffoldReport->integrityErrors);
        // Live file untouched.
        self::assertSame("#!/bin/sh\necho old\n", file_get_contents($this->projectRoot . '/bin/semitexa'));
    }

    public function testPackageDriftRunsButDoesNotInvokeComposer(): void
    {
        // Mark composer.lock with a different version than installed.json → vendor_stale.
        $this->writeComposerFixture(installedVersion: '2026.05.08.1640', lockedVersion: '2026.05.11.1359');
        $this->writeProjectFile('bin/semitexa', file_get_contents($this->scaffoldRoot . '/bin/semitexa'));

        $beforeSnapshot = $this->fsSnapshot();
        $report = $this->orchestrator()->plan();
        $afterSnapshot = $this->fsSnapshot();

        self::assertSame($beforeSnapshot, $afterSnapshot, 'plan() must be read-only — no composer mutations.');
        self::assertNotNull($report->packageDrift);
        $entry = $report->packageDrift->entryByName('semitexa/update');
        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::VendorStale, $entry->status);
        self::assertStringContainsString('composer install', $entry->actionHint);
    }

    public function testPathRepositoryAndDevConstraintAreNotActionable(): void
    {
        $this->writeComposerFixture(
            installedVersion: '2026.05.11.1359',
            lockedVersion: '2026.05.11.1359',
            extraComposerJson: ['semitexa/platform-ui' => '@dev'],
            extraLockEntries: [
                ['name' => 'semitexa/platform-ui', 'version' => 'dev-develop'],
            ],
            extraInstalledEntries: [
                ['name' => 'semitexa/platform-ui', 'version' => 'dev-develop'],
            ],
        );
        $this->writeProjectFile('bin/semitexa', file_get_contents($this->scaffoldRoot . '/bin/semitexa'));

        $report = $this->orchestrator()->plan();
        $dev = $report->packageDrift->entryByName('semitexa/platform-ui');
        self::assertSame(PackageDriftStatus::DevConstraint, $dev->status);
        self::assertFalse($dev->status->isActionable());
    }

    public function testBinSemitexaReplacementDoesNotTriggerReExec(): void
    {
        $oldBin = "#!/bin/sh\necho old\n";
        $this->writeProjectFile('bin/semitexa', $oldBin);

        // The orchestrator must just return normally; if it tried to re-exec we'd
        // see process-level state change. A normal return + stages array confirms
        // no re-exec was attempted.
        $stages = $this->orchestrator()->run(dryRun: false);
        self::assertNotEmpty($stages);
        $scaffold = $stages[0]->scaffoldReport;
        self::assertNotNull($scaffold);
        self::assertSame(
            ScaffoldSyncOutcome::Applied,
            $scaffold->resultByPath('bin/semitexa')->outcome,
        );
    }

    public function testLocallyModifiedFileDoesNotFailTheRun(): void
    {
        // bin/semitexa is locally modified → WriteNew. The orchestrator must
        // still proceed to pre-patches / orm-sync / apply-patches / post-patches.
        $this->writeProjectFile('bin/semitexa', "operator-customized\n");

        $stages = $this->orchestrator()->run(dryRun: false);
        $names = array_map(static fn (\Semitexa\Update\Domain\Model\OrchestratorStage $s) => $s->name, $stages);

        self::assertSame('scaffold-sync', $names[0]);
        self::assertContains('pre-patches', $names);
        self::assertContains('orm-sync', $names);
        self::assertContains('apply-patches', $names);
        self::assertContains('post-patches', $names);

        // The scaffold-sync stage itself reports success (WriteNew is Applied, not Failed).
        self::assertTrue($stages[0]->isSuccess());
    }

    public function testMixedReleaseSetDoesNotAbortTheRun(): void
    {
        // semitexa/update at one date, an extra semitexa/* at a different date
        // → the report flags mixed_release_set, but the run must complete.
        $this->writeComposerFixture(
            installedVersion: '2026.05.11.1359',
            lockedVersion: '2026.05.11.1359',
            extraComposerJson: ['semitexa/core' => '2026.05.08.1640'],
            extraLockEntries: [['name' => 'semitexa/core', 'version' => '2026.05.08.1640']],
            extraInstalledEntries: [['name' => 'semitexa/core', 'version' => '2026.05.08.1640']],
        );
        $this->writeProjectFile('bin/semitexa', file_get_contents($this->scaffoldRoot . '/bin/semitexa'));

        $report = $this->orchestrator()->plan();
        self::assertNotNull($report->packageDrift);
        self::assertFalse($report->packageDrift->releaseSetCoherent);
        self::assertNotEmpty($report->packageDrift->mixedReleaseDates);

        // Run must still complete cleanly — drift never aborts.
        $stages = $this->orchestrator()->run(dryRun: false);
        foreach ($stages as $stage) {
            self::assertTrue(
                $stage->isSuccess(),
                "Stage {$stage->name} unexpectedly failed under mixed_release_set; drift is informational only.",
            );
        }
    }

    public function testCreateActionMakesParentDirectoryWhenMissing(): void
    {
        // Remove the project's bin/ directory entirely so Create has to make it.
        $this->rrm($this->projectRoot . '/bin');
        self::assertDirectoryDoesNotExist($this->projectRoot . '/bin');

        $stages = $this->orchestrator()->run(dryRun: false);

        self::assertDirectoryExists($this->projectRoot . '/bin');
        self::assertFileExists($this->projectRoot . '/bin/semitexa');
        $bin = $stages[0]->scaffoldReport->resultByPath('bin/semitexa');
        self::assertSame(\Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome::Applied, $bin->outcome);
        self::assertSame(\Semitexa\Update\Domain\Enum\ScaffoldSyncAction::Create, $bin->action);
    }

    public function testIdempotentSecondRun(): void
    {
        $oldBin = "#!/bin/sh\necho old\n";
        $this->writeProjectFile('bin/semitexa', $oldBin);

        $this->orchestrator()->run(dryRun: false);
        $stages = $this->orchestrator()->run(dryRun: false);

        $bin = $stages[0]->scaffoldReport->resultByPath('bin/semitexa');
        self::assertSame(ScaffoldSyncOutcome::NoOp, $bin->outcome);
        self::assertSame(ScaffoldSyncStatus::Current, $bin->status);
    }

    private function orchestrator(): UpdateOrchestrator
    {
        $hasher = new ScaffoldHasher();
        return new UpdateOrchestrator(
            runner: $this->makeRunner(),
            migrationGateway: $this->makeGateway(),
            connection: 'default',
            driftInspector: new PackageDriftInspector(),
            scaffoldLoader: new ScaffoldManifestLoader(),
            scaffoldClassifier: new ScaffoldFileClassifier($hasher),
            scaffoldEngine: new ScaffoldSyncEngine($hasher),
            projectRoot: $this->projectRoot,
            scaffoldRoot: $this->scaffoldRoot,
            manifestPath: $this->manifestPath,
        );
    }

    private function makeRunner(): UpdateRunner
    {
        $db = new SqliteAdapter('sqlite::memory:');
        return new UpdateRunner(
            discovery: new DataPatchDiscovery(new ClassDiscovery()),
            dagBuilder: new DagBuilder(),
            journal: new JournalRepository($db),
            adapter: $db,
            compatibilityChecker: new SchemaCompatibilityChecker(new LiveSchemaInspector($db)),
            semitexaVersion: null,
        );
    }

    private function makeGateway(): OrmMigrationGatewayInterface
    {
        return new class implements OrmMigrationGatewayInterface {
            public function inspect(string $connection): SchemaSyncStatus
            {
                return new SchemaSyncStatus(
                    inSync: true,
                    pendingOperations: 0,
                    destructiveOperations: 0,
                    summary: 'ORM in sync (test stub).',
                );
            }

            public function synchronize(string $connection, bool $allowDestructive = false, bool $dryRun = false): SchemaSyncResult
            {
                return new SchemaSyncResult(
                    executedOperations: 0,
                    skippedDestructive: 0,
                    dryRun: $dryRun,
                    summary: 'ORM no-op (test stub).',
                );
            }
        };
    }

    private function writeScaffoldFixture(): void
    {
        file_put_contents($this->scaffoldRoot . '/bin/semitexa', "#!/bin/sh\necho new-scaffold\n");
    }

    private function writeManifest(bool $corruptCurrentHash = false): void
    {
        $scaffoldBin = "#!/bin/sh\necho new-scaffold\n";
        $oldBin      = "#!/bin/sh\necho old\n";
        $currentHash = $corruptCurrentHash
            ? hash('sha256', 'something-completely-different')
            : hash('sha256', $scaffoldBin);

        $manifest = [
            'schema_version' => 'semitexa.scaffold-manifest/v1',
            'generated_at' => '2026-05-12T00:00:00+00:00',
            'files' => [[
                'path' => 'bin/semitexa',
                'current_sha256' => $currentHash,
                'previous_sha256' => [hash('sha256', $oldBin)],
                'category' => 'executable',
                'critical' => true,
                'auto_update' => true,
                'preserve_executable' => true,
                'notes' => 'CLI entry.',
            ]],
        ];
        file_put_contents($this->manifestPath, json_encode($manifest));
    }

    /**
     * @param list<array<string,string>> $extraLockEntries
     * @param list<array<string,string>> $extraInstalledEntries
     * @param array<string,string>       $extraComposerJson
     */
    private function writeComposerFixture(
        string $installedVersion = '2026.05.11.1359',
        string $lockedVersion = '2026.05.11.1359',
        array $extraComposerJson = [],
        array $extraLockEntries = [],
        array $extraInstalledEntries = [],
    ): void {
        $composerJson = ['require' => array_merge(
            ['semitexa/update' => $lockedVersion],
            $extraComposerJson,
        )];
        file_put_contents($this->projectRoot . '/composer.json', json_encode($composerJson));

        $lockEntries = array_merge(
            [['name' => 'semitexa/update', 'version' => $lockedVersion]],
            $extraLockEntries,
        );
        file_put_contents(
            $this->projectRoot . '/composer.lock',
            json_encode(['packages' => $lockEntries, 'packages-dev' => []]),
        );

        $installedDir = $this->projectRoot . '/vendor/composer';
        if (!is_dir($installedDir)) {
            mkdir($installedDir, 0777, true);
        }
        $installedEntries = array_merge(
            [['name' => 'semitexa/update', 'version' => $installedVersion]],
            $extraInstalledEntries,
        );
        file_put_contents(
            $installedDir . '/installed.json',
            json_encode(['packages' => $installedEntries]),
        );
    }

    private function writeProjectFile(string $relative, string $bytes): void
    {
        $absolute = $this->projectRoot . '/' . $relative;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, $bytes);
    }

    /**
     * @return array<string, string>
     */
    private function fsSnapshot(): array
    {
        $hashes = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($hashes);
        return $hashes;
    }

    private function rrm(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }
}
