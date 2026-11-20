<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
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
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Domain\Contract\OrmMigrationGatewayInterface;
use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;
use Semitexa\Update\Domain\Model\OrchestratorStage;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSource;
use Semitexa\Update\Domain\Model\SchemaSyncResult;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;

/**
 * The scaffold from two sources, as `bin/semitexa update` runs it from step 2
 * of one source for bin/semitexa: semitexa/installer's files, and this
 * package's agent docs with the retired list.
 *
 * Each source is classified against its own root, the plan is one, and an
 * integrity failure anywhere writes nothing anywhere.
 */
final class UpdateOrchestratorScaffoldSourcesTest extends TestCase
{
    private const BIN_OLD = "#!/bin/sh\necho old\n";
    private const BIN_NEW = "#!/bin/sh\necho new\n";
    private const DOC_OLD = "# Agents, old\n";
    private const DOC_NEW = "# Agents, new\n";

    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/semitexa-scaffold-sources-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/project/bin', 0777, true);
        $this->source('installer', ['bin/semitexa' => [self::BIN_NEW, [self::BIN_OLD], 'executable']]);
        $this->source('update', ['AGENTS.md' => [self::DOC_NEW, [self::DOC_OLD], 'agent_docs']]);
        $this->project('bin/semitexa', self::BIN_OLD);
        $this->project('AGENTS.md', self::DOC_OLD);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testEachSourcesFileComesFromItsOwnRoot(): void
    {
        $report = $this->scaffoldStage($this->orchestrator()->run(dryRun: false));

        self::assertTrue($report->isSuccess(), implode('; ', $report->integrityErrors));
        self::assertSame(ScaffoldSyncOutcome::Applied, $report->resultByPath('bin/semitexa')?->outcome);
        self::assertSame(ScaffoldSyncOutcome::Applied, $report->resultByPath('AGENTS.md')?->outcome);
        self::assertSame(self::BIN_NEW, file_get_contents($this->base . '/project/bin/semitexa'));
        self::assertSame(self::DOC_NEW, file_get_contents($this->base . '/project/AGENTS.md'));
    }

    public function testAPathBothSourcesShipIsAnIntegrityErrorAndNothingIsWritten(): void
    {
        $this->source('update', [
            'AGENTS.md' => [self::DOC_NEW, [self::DOC_OLD], 'agent_docs'],
            'bin/semitexa' => [self::BIN_NEW, [self::BIN_OLD], 'executable'],
        ]);
        $orchestrator = $this->orchestrator();

        $plan = $orchestrator->plan()->scaffoldPlan;
        self::assertNotNull($plan);
        self::assertContains('bin/semitexa is shipped by both semitexa/installer and semitexa/update.', $plan->integrityErrors);

        $report = $this->scaffoldStage($orchestrator->run(dryRun: false));
        self::assertFalse($report->isSuccess());
        $this->assertProjectUntouched();
    }

    public function testAnIntegrityFailureInOneSourceStopsTheOther(): void
    {
        // The installer's file no longer matches its manifest's current hash.
        file_put_contents($this->base . '/installer/scaffold/bin/semitexa', "#!/bin/sh\necho edited after the manifest\n");

        $report = $this->scaffoldStage($this->orchestrator()->run(dryRun: false));

        self::assertFalse($report->isSuccess());
        self::assertNotSame([], $report->integrityErrors);
        $this->assertProjectUntouched();
    }

    public function testAnIntegrityFailureInTheSecondSourceStopsTheFirst(): void
    {
        // The order of the sources is no reason to write the first one's files.
        file_put_contents($this->base . '/update/scaffold/AGENTS.md', "# Agents, edited after the manifest\n");

        $report = $this->scaffoldStage($this->orchestrator()->run(dryRun: false));

        self::assertFalse($report->isSuccess());
        self::assertNotSame([], $report->integrityErrors);
        $this->assertProjectUntouched();
    }

    public function testADryRunAcrossTwoSourcesWritesNothingAndSaysWhatItWouldDo(): void
    {
        $report = $this->scaffoldStage($this->orchestrator()->run(dryRun: true));

        self::assertTrue($report->isSuccess(), implode('; ', $report->integrityErrors));
        self::assertSame(ScaffoldSyncOutcome::WouldApply, $report->resultByPath('bin/semitexa')?->outcome);
        self::assertSame(ScaffoldSyncOutcome::WouldApply, $report->resultByPath('AGENTS.md')?->outcome);
        $this->assertProjectUntouched();
    }

    public function testAManifestOneSourceCannotReadStopsBothAndIsNamed(): void
    {
        file_put_contents($this->base . '/installer/scaffold-manifest.json', '{"files": [');
        $orchestrator = $this->orchestrator();

        $plan = $orchestrator->plan()->scaffoldPlan;
        self::assertNotNull($plan);
        self::assertStringStartsWith('The scaffold manifest of semitexa/installer cannot be read', $plan->integrityErrors[0] ?? '');

        self::assertFalse($this->scaffoldStage($orchestrator->run(dryRun: false))->isSuccess());
        $this->assertProjectUntouched();
    }

    /**
     * Packages of two releases: update's older manifest retires a file the newer
     * installer ships again. The shipped file is synced, not deleted.
     */
    public function testAPathOneSourceRetiresAndTheOtherShipsIsShipped(): void
    {
        $legacyOld = "services: {}\n";
        $legacyNew = "services: { app: {} }\n";
        $this->source('installer', [
            'bin/semitexa' => [self::BIN_NEW, [self::BIN_OLD], 'executable'],
            'docker-compose.legacy.yml' => [$legacyNew, [$legacyOld], 'infrastructure'],
        ]);
        $this->source('update', ['AGENTS.md' => [self::DOC_NEW, [self::DOC_OLD], 'agent_docs']], retired: ['docker-compose.legacy.yml' => [$legacyOld]]);
        $this->project('docker-compose.legacy.yml', $legacyOld);

        $report = $this->scaffoldStage($this->orchestrator()->run(dryRun: false));

        self::assertTrue($report->isSuccess(), implode('; ', $report->integrityErrors));
        self::assertSame(ScaffoldSyncOutcome::Applied, $report->resultByPath('docker-compose.legacy.yml')?->outcome);
        self::assertSame($legacyNew, file_get_contents($this->base . '/project/docker-compose.legacy.yml'));
    }

    /**
     * Fable's review of update 1959e8d: the retired versions were dropped, and a
     * project's untouched copy at one of them read as an edit.
     */
    public function testARetiredVersionOfAPathShippedAgainIsReplacedNotTakenForAnEdit(): void
    {
        $retiredVersion = "services: { old: {} }\n";
        $this->source('installer', [
            'bin/semitexa' => [self::BIN_NEW, [self::BIN_OLD], 'executable'],
            'docker-compose.legacy.yml' => ["services: { app: {} }\n", [], 'infrastructure'],
        ]);
        $this->source('update', ['AGENTS.md' => [self::DOC_NEW, [self::DOC_OLD], 'agent_docs']], retired: ['docker-compose.legacy.yml' => [$retiredVersion]]);
        $this->project('docker-compose.legacy.yml', $retiredVersion);

        $report = $this->scaffoldStage($this->orchestrator()->run(dryRun: false));

        self::assertTrue($report->isSuccess(), implode('; ', $report->integrityErrors));
        self::assertSame(ScaffoldSyncOutcome::Applied, $report->resultByPath('docker-compose.legacy.yml')?->outcome);
        self::assertSame("services: { app: {} }\n", file_get_contents($this->base . '/project/docker-compose.legacy.yml'));
    }

    public function testARetiredFileNoSourceShipsIsStillRemoved(): void
    {
        $gone = "version: '3'\n";
        $this->source('update', ['AGENTS.md' => [self::DOC_NEW, [self::DOC_OLD], 'agent_docs']], retired: ['docker-compose.gone.yml' => [$gone]]);
        $this->project('docker-compose.gone.yml', $gone);

        $report = $this->scaffoldStage($this->orchestrator()->run(dryRun: false));

        self::assertTrue($report->isSuccess(), implode('; ', $report->integrityErrors));
        self::assertFileDoesNotExist($this->base . '/project/docker-compose.gone.yml');
    }

    private function assertProjectUntouched(): void
    {
        self::assertSame(self::BIN_OLD, file_get_contents($this->base . '/project/bin/semitexa'));
        self::assertSame(self::DOC_OLD, file_get_contents($this->base . '/project/AGENTS.md'));
    }

    /**
     * @param list<OrchestratorStage> $stages
     */
    private function scaffoldStage(array $stages): \Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport
    {
        foreach ($stages as $stage) {
            if ($stage->name === 'scaffold-sync' && $stage->scaffoldReport !== null) {
                return $stage->scaffoldReport;
            }
        }
        self::fail('the run had no scaffold-sync stage: ' . implode(', ', array_map(static fn (OrchestratorStage $s): string => $s->name, $stages)));
    }

    /**
     * Writes a source: its files, and a manifest naming each with its history.
     *
     * @param array<string, array{string, list<string>, string}> $files path => [content, earlier contents, category]
     * @param array<string, list<string>> $retired path => contents it shipped with
     */
    private function source(string $name, array $files, array $retired = []): void
    {
        $root = $this->base . '/' . $name;
        exec('rm -rf ' . escapeshellarg($root));
        $entries = [];
        foreach ($files as $path => [$content, $earlier, $category]) {
            $file = $root . '/scaffold/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
            $entries[] = [
                'path' => $path,
                'current_sha256' => hash('sha256', $content),
                'previous_sha256' => array_map(static fn (string $c): string => hash('sha256', $c), $earlier),
                'category' => $category,
                'critical' => $category === 'executable',
                'auto_update' => true,
                'preserve_executable' => $category === 'executable',
                'notes' => '',
            ];
        }
        $manifest = ['schema_version' => 'semitexa.scaffold-manifest/v1', 'generated_at' => '2026-11-20T00:00:00+00:00', 'files' => $entries];
        if ($retired !== []) {
            $manifest['retired'] = [];
            foreach ($retired as $path => $contents) {
                $manifest['retired'][] = ['path' => $path, 'shipped_sha256' => array_map(static fn (string $c): string => hash('sha256', $c), $contents)];
            }
        }
        file_put_contents($root . '/scaffold-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function project(string $path, string $content): void
    {
        $file = $this->base . '/project/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    private function orchestrator(): UpdateOrchestrator
    {
        $hasher = new ScaffoldHasher();
        $db = new SqliteAdapter('sqlite::memory:');

        return new UpdateOrchestrator(
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
            projectRoot: $this->base . '/project',
            scaffoldSources: [
                new ScaffoldSource('semitexa/installer', $this->base . '/installer/scaffold', $this->base . '/installer/scaffold-manifest.json'),
                new ScaffoldSource('semitexa/update', $this->base . '/update/scaffold', $this->base . '/update/scaffold-manifest.json'),
            ],
        );
    }
}
