<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Domain\Model\Composer\NewSkeletonPackage;
use Semitexa\Update\Application\Console\Command\UpdateCommand;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSource;
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
use Semitexa\Update\Application\Service\Composer\ComposerExecutorInterface;
use Semitexa\Update\Application\Service\Composer\ComposerUpdateRunner;
use Semitexa\Update\Application\Service\Composer\UpstreamVersionResolverInterface;
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

    /**
     * The advisory is the last thing a finished run says, once. Rendered with
     * the plan, before composer, it proposed semitexa/installer to a project
     * the same run was about to give it, and printed it again in the
     * continuation process (20.11).
     */
    public function testTheSkeletonAdvisoryComesOnceAfterTheStages(): void
    {
        foreach ([[], ['--dry-run' => true]] as $options) {
            $tester = $this->commandTester(missingSkeletonPackages: static fn (): array => [
                new NewSkeletonPackage(name: 'semitexa/media', pinnedVersion: '2026.11.20.0947', description: 'Media assets'),
            ]);
            $tester->execute($options);
            $output = $tester->getDisplay();
            $mode = $options === [] ? 'real run' : 'dry run';

            self::assertSame(1, substr_count($output, 'New skeleton packages available'), $mode . ': once');
            self::assertStringContainsString('composer require semitexa/media:2026.11.20.0947', $output, $mode);
            // The "Stages" title opens what the run did; the plan comes before it.
            preg_match('/Stages\n\s*=+\n/', $output, $stages, PREG_OFFSET_CAPTURE);
            $stagesAt = $stages[0][1] ?? -1;
            self::assertGreaterThan(-1, $stagesAt, $mode . ': the stages are rendered');
            self::assertGreaterThan($stagesAt, strpos($output, 'New skeleton packages available'), $mode . ': after the stages, not with the plan');
        }
    }

    /**
     * tk-e2e-seeding-reads-like-a-framework-update: test:run seeds the E2E
     * database with `update --no-composer --no-scaffold`, and what that printed
     * (a composer plan, "Update completed. Installed release: ...", packages to
     * add) read as the framework having been updated. A run that calls no
     * composer says what it did and nothing it did not.
     */
    public function testARunWithoutComposerSaysWhatItDidAndNothingAboutPackages(): void
    {
        foreach ([
            [['--no-composer' => true, '--no-scaffold' => true], 'Schema and data patches applied. Composer was not run (--no-composer): no package was updated.'],
            [['--no-composer' => true], 'Scaffold, schema and data patches applied. Composer was not run (--no-composer): no package was updated.'],
            [['--no-composer' => true, '--no-scaffold' => true, '--dry-run' => true], 'Dry-run: no patches and no DDL were executed.'],
        ] as [$options, $said]) {
            $tester = $this->commandTester($this->composerThatCouldRun(), static fn (): array => [
                new NewSkeletonPackage(name: 'semitexa/media', pinnedVersion: '2026.11.20.0947', description: 'Media assets'),
            ]);
            $tester->execute($options);
            $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
            $mode = implode(' ', array_keys($options));

            self::assertSame(0, $tester->getStatusCode(), $mode . ': ' . $output);
            self::assertStringContainsString($said, $output, $mode);
            self::assertStringContainsString('Semitexa Update Plan (--no-composer: no package is updated)', $output, $mode);
            self::assertStringContainsString('ORM schema', $output, $mode . ': what it does is still planned');
            self::assertStringContainsString('Composer phase skipped via --no-composer.', $output, $mode);
            foreach (['Composer package update', 'Command: composer', 'Update completed', 'Installed release', 'New skeleton packages available', 'composer require'] as $notSaid) {
                self::assertStringNotContainsString($notSaid, $output, $mode . ': says nothing it did not do');
            }
        }
    }

    /** Review update#127: the two together ran no stage and still said the patches were applied. */
    public function testNoComposerWithComposerOnlyIsRefusedBeforeAnythingIsPlanned(): void
    {
        $tester = $this->commandTester($this->composerThatCouldRun(), static fn (): array => []);
        $tester->execute(['--no-composer' => true, '--composer-only' => true, '--no-scaffold' => true]);
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(2, $tester->getStatusCode(), $output);
        self::assertStringContainsString('--no-composer and --composer-only exclude each other', $output);
        self::assertStringNotContainsString('Semitexa Update Plan', $output, 'refused before anything is planned');
        self::assertStringNotContainsString('patches applied', $output);
    }

    /** The same project and the same composer, without --no-composer: all of it is said, as before. */
    public function testARunWithComposerStillSaysItsPlanItsReleaseAndThatItCompleted(): void
    {
        $skeleton = static fn (): array => [
            new NewSkeletonPackage(name: 'semitexa/media', pinnedVersion: '2026.11.20.0947', description: 'Media assets'),
        ];

        $tester = $this->commandTester($this->composerThatCouldRun(), $skeleton);
        $tester->execute(['--dry-run' => true, '--no-scaffold' => true]);
        $output = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^Semitexa Update Plan\n=+$/m', $output, 'the title as it was');
        self::assertStringContainsString('Composer package update', $output);
        self::assertStringContainsString('Command: composer', $output);
        self::assertStringContainsString('New skeleton packages available', $output);

        $tester = $this->commandTester(null, $skeleton);
        $tester->execute(['--no-scaffold' => true]);
        $output = $tester->getDisplay();
        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString('[OK] Update completed.', $output);
        self::assertStringContainsString('Installed release: ', $output);
        self::assertStringContainsString('New skeleton packages available', $output);
        self::assertStringNotContainsString('no package was updated', $output);
    }

    private function composerThatCouldRun(): ComposerUpdateRunner
    {
        return new ComposerUpdateRunner(
            new class implements ComposerExecutorInterface {
                public function isAvailable(): bool { return true; }
                public function containerError(): string { return ''; }
                public function run(array $args, string $projectRoot, array $env = []): array { return ['exitCode' => 0, 'output' => '']; }
            },
            new class implements UpstreamVersionResolverInterface {
                public function stableVersions(string $package): array { return []; }
                public function requiresOf(string $package, string $version): ?array { return null; }
            },
        );
    }

    public function testRealRunOutputRecommendsManualRerunAfterBinReplacement(): void
    {
        $tester = $this->commandTester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('bin/semitexa was replaced', $output);
        self::assertStringContainsString('Rerun `bin/semitexa update`', $output, 'Must surface the manual-rerun recommendation, not auto re-exec.');
    }

    public function testLocallyModifiedScaffoldFilePrintsManualReviewMessageByDefault(): void
    {
        file_put_contents($this->projectRoot . '/bin/semitexa', "operator-edited\n");

        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('locally_modified', $output);
        self::assertStringContainsString('Live file left untouched.', $output);
        self::assertStringContainsString(
            'Run `bin/semitexa update --write-scaffold-candidates` to generate bin/semitexa.new',
            $output,
        );
        // Crucially: no .new file actually got created on disk by the dry-run.
        self::assertFileDoesNotExist($this->projectRoot . '/bin/semitexa.new');
        // And the manual-review tally appears in the per-stage summary.
        self::assertStringContainsString('manual-review', $output);
    }

    public function testWriteScaffoldCandidatesFlagSwitchesToWriteNewRendering(): void
    {
        file_put_contents($this->projectRoot . '/bin/semitexa', "operator-edited\n");

        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true, '--write-scaffold-candidates' => true]);

        $output = $tester->getDisplay();
        // With the flag, the entry goes back to write_new, dry-run reports would_apply.
        self::assertStringContainsString('write_new', $output);
        self::assertStringContainsString('would_apply', $output);
        self::assertStringContainsString('bin/semitexa.new', $output);
        // Still nothing actually written under dry-run.
        self::assertFileDoesNotExist($this->projectRoot . '/bin/semitexa.new');
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

    /**
     * Review of #47: a dry run is a check that CI and scripts read by exit code.
     * One whose composer stage would fail was rendered, then answered with 0.
     */
    public function testADryRunThatFindsAFailingStageExitsNonZero(): void
    {
        $refusing = new ComposerUpdateRunner(
            new class implements ComposerExecutorInterface {
                public function isAvailable(): bool { return false; }
                public function containerError(): string { return 'not in the container (test)'; }
                public function run(array $args, string $projectRoot, array $env = []): array { return ['exitCode' => 0, 'output' => '']; }
            },
            new class implements UpstreamVersionResolverInterface {
                public function stableVersions(string $package): ?array { return []; }
                public function requiresOf(string $package, string $version): ?array { return null; }
            },
        );

        $tester = $this->commandTester($refusing);
        $tester->execute(['--dry-run' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Dry-run: stage "composer-update" would fail.', $tester->getDisplay());
    }

    /**
     * @param (\Closure(): (list<NewSkeletonPackage>|null))|null $missingSkeletonPackages null: none, so no test asks Packagist
     */
    private function commandTester(?ComposerUpdateRunner $composerRunner = null, ?\Closure $missingSkeletonPackages = null): CommandTester
    {
        $orchestrator = $this->buildOrchestrator($composerRunner);

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
        new \ReflectionProperty($cmd, 'missingSkeletonPackages')->setValue($cmd, $missingSkeletonPackages ?? static fn (): ?array => null);
        $cmd->setName('update');

        return new CommandTester($cmd);
    }

    private function buildOrchestrator(?ComposerUpdateRunner $composerRunner = null): UpdateOrchestrator
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
            scaffoldSources: [new ScaffoldSource('semitexa/update', $this->scaffoldRoot, $this->manifestPath)],
            composerRunner: $composerRunner,
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
