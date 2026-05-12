<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Console\Command\UpdateCommand;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Application\Service\LiveSchemaInspector;
use Semitexa\Update\Application\Service\PackageDriftInspector;
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
use Semitexa\Update\Domain\Model\SchemaSyncResult;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Operator-facing rendering smoke test. Most rendering coverage is provided by
 * the live semitexa.pl dry-run smoke (uw-4 final report) plus the data-level
 * assertions in {@see UpdateOrchestratorWiringTest}. This file pins the few
 * substrings the operator-facing summary must keep, so subtle regressions in
 * UpdateCommand's renderer don't slip through.
 */
final class UpdateCommandRenderingTest extends TestCase
{
    private string $projectRoot;
    private string $scaffoldRoot;
    private string $manifestPath;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/semitexa-uw5-render-' . bin2hex(random_bytes(8));
        $this->projectRoot  = $base . '/project';
        $this->scaffoldRoot = $base . '/scaffold';
        $this->manifestPath = $base . '/scaffold-manifest.json';

        mkdir($this->projectRoot . '/bin', 0777, true);
        mkdir($this->scaffoldRoot . '/bin', 0777, true);

        file_put_contents($this->scaffoldRoot . '/bin/semitexa', "#!/bin/sh\necho new\n");
        file_put_contents($this->projectRoot . '/bin/semitexa', "#!/bin/sh\necho old\n");
        $this->writeManifest();
        $this->writeComposerFixture(installedVersion: '2026.05.08.1640', lockedVersion: '2026.05.11.1359');
    }

    protected function tearDown(): void
    {
        $this->rrm(dirname($this->projectRoot));
    }

    public function testDryRunOutputRendersDriftAndScaffoldSections(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('Composer package drift', $output);
        self::assertStringContainsString('declared:', $output);
        self::assertStringContainsString('locked:', $output);
        self::assertStringContainsString('installed:', $output);
        self::assertStringContainsString('Composer is not invoked by `bin/semitexa update`', $output);

        self::assertStringContainsString('Scaffold sync', $output);
        self::assertStringContainsString('bin/semitexa', $output);
        self::assertStringContainsString('known_previous', $output);
        self::assertStringContainsString('replace', $output);
        self::assertStringContainsString('Dry-run: would apply', $output);
    }

    public function testRealRunOutputRecommendsManualRerunAfterBinReplacement(): void
    {
        $tester = $this->commandTester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('bin/semitexa was replaced', $output);
        self::assertStringContainsString('Rerun `bin/semitexa update`', $output, 'Must surface the manual-rerun recommendation, not auto re-exec.');
    }

    public function testLocallyModifiedScaffoldFilePrintsManualReviewMessage(): void
    {
        file_put_contents($this->projectRoot . '/bin/semitexa', "operator-edited\n");

        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('locally_modified', $output);
        self::assertStringContainsString('bin/semitexa.new', $output);
        self::assertStringContainsString('Manual review required', $output);
    }

    public function testIntegrityFailureRendersErrorBanner(): void
    {
        // Make the manifest assert a hash that doesn't match the scaffold.
        $this->writeManifest(corruptCurrentHash: true);

        $tester = $this->commandTester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Scaffold integrity check failed', $output);
        self::assertStringContainsString('manifest current_sha256 is', $output);
        self::assertNotSame(0, $tester->getStatusCode(), 'Integrity failure must surface as a non-zero exit code.');
    }

    private function commandTester(): CommandTester
    {
        $orchestrator = $this->buildOrchestrator();

        // UpdateCommand uses #[InjectAsReadonly] property injection. In a real
        // container the property is set after construction; in this test we
        // bypass DI and assign it via reflection. The factory is a stub whose
        // sole job is to hand back our pre-built orchestrator.
        $factory = new class($orchestrator) extends UpdateRunnerFactory {
            public function __construct(private readonly UpdateOrchestrator $stub) {}
            public function orchestrator(string $connection = 'default', ?string $semitexaVersion = null): UpdateOrchestrator
            {
                return $this->stub;
            }
        };

        $cmd = new UpdateCommand();
        $ref = new \ReflectionProperty($cmd, 'runnerFactory');
        $ref->setValue($cmd, $factory);
        $cmd->setName('update');

        return new CommandTester($cmd);
    }

    private function buildOrchestrator(): UpdateOrchestrator
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $runner = new UpdateRunner(
            discovery: new DataPatchDiscovery(new ClassDiscovery()),
            dagBuilder: new DagBuilder(),
            journal: new JournalRepository($db),
            adapter: $db,
            compatibilityChecker: new SchemaCompatibilityChecker(new LiveSchemaInspector($db)),
            semitexaVersion: null,
        );

        $gateway = new class implements OrmMigrationGatewayInterface {
            public function inspect(string $connection): SchemaSyncStatus
            {
                return new SchemaSyncStatus(true, 0, 0, 'ORM in sync (test stub).');
            }
            public function synchronize(string $connection, bool $allowDestructive = false, bool $dryRun = false): SchemaSyncResult
            {
                return new SchemaSyncResult(0, 0, $dryRun, 'ORM no-op (test stub).');
            }
        };

        $hasher = new ScaffoldHasher();
        return new UpdateOrchestrator(
            runner: $runner,
            migrationGateway: $gateway,
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

    private function writeManifest(bool $corruptCurrentHash = false): void
    {
        $newBin = "#!/bin/sh\necho new\n";
        $oldBin = "#!/bin/sh\necho old\n";
        $currentHash = $corruptCurrentHash
            ? hash('sha256', 'something-totally-different')
            : hash('sha256', $newBin);

        file_put_contents($this->manifestPath, json_encode([
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
                'notes' => 'CLI.',
            ]],
        ]));
    }

    private function writeComposerFixture(string $installedVersion, string $lockedVersion): void
    {
        file_put_contents(
            $this->projectRoot . '/composer.json',
            json_encode(['require' => ['semitexa/update' => $lockedVersion]]),
        );
        file_put_contents(
            $this->projectRoot . '/composer.lock',
            json_encode([
                'packages' => [['name' => 'semitexa/update', 'version' => $lockedVersion]],
                'packages-dev' => [],
            ]),
        );
        mkdir($this->projectRoot . '/vendor/composer', 0777, true);
        file_put_contents(
            $this->projectRoot . '/vendor/composer/installed.json',
            json_encode(['packages' => [['name' => 'semitexa/update', 'version' => $installedVersion]]]),
        );
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
