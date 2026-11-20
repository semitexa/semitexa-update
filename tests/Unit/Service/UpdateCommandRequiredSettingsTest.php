<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Console\Command\UpdateCommand;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Application\Service\LiveSchemaInspector;
use Semitexa\Update\Application\Service\RequiredSettings\DoctorProbe;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Application\Service\SchemaCompatibilityChecker;
use Semitexa\Update\Application\Service\UpdateOrchestrator;
use Semitexa\Update\Application\Service\UpdateRunner;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Domain\Contract\OrmMigrationGatewayInterface;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\SchemaSyncResult;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The required-settings stage of `bin/semitexa update`.
 *
 * 20.11: a consumer deployed a release whose code needed APP_SECRET to a prod
 * `.env` that had none. The update ended clean and its collaborative forms
 * answered 500 for an hour. The doctor check failed correctly all along;
 * nothing asked it.
 */
final class UpdateCommandRequiredSettingsTest extends TestCase
{
    private const HINT = 'Set APP_SECRET in .env to a long random value: openssl rand -hex 32';

    private string $projectRoot;
    /** @var \ArrayObject<int, string> what the ORM stage was asked to do */
    private \ArrayObject $ormCalls;
    private RunJournalRepository $runJournal;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-required-settings-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0777, true);
        // prod, and no APP_SECRET.
        file_put_contents($this->projectRoot . '/.env', "APP_ENV=prod\nAPP_NAME=Fixture\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRoot));
    }

    public function testAMissingRequiredSettingEndsTheUpdateBeforeTheDatabaseIsTouched(): void
    {
        $tester = $this->commandTester(self::report('fail', ['APP_SECRET']));
        $tester->execute(['--no-composer' => true]);
        $output = $tester->getDisplay();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('APP_SECRET', $output);
        self::assertStringContainsString('check app.secret, semitexa/core', $output);
        self::assertStringContainsString('APP_ENV=prod', $output, 'the environment judged, read from the fixture .env');
        self::assertStringContainsString('fix: ' . self::HINT, $output);
        self::assertStringContainsString('Update aborted at stage "required-settings".', $output);
        self::assertSame([], $this->ormCalls->getArrayCopy(), 'the stage stands before the ORM: an abort leaves the database as it was');

        $record = $this->runJournal->findRecent()[0];
        self::assertSame(RunOutcome::Failed, $record->outcome);
        self::assertSame('required-settings', $record->failedStage);
        self::assertStringContainsString('APP_SECRET', (string) $record->error);
    }

    /** A dry run is a check read by exit code: it names the setting too, and writes nothing. */
    public function testADryRunFindsTheMissingSettingToo(): void
    {
        $tester = $this->commandTester(self::report('fail', ['APP_SECRET']));
        $tester->execute(['--no-composer' => true, '--dry-run' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('fix: ' . self::HINT, $tester->getDisplay());
        self::assertStringContainsString('Dry-run: stage "required-settings" would fail.', $tester->getDisplay());
        self::assertSame([], $this->ormCalls->getArrayCopy());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reportsThatBlockNothing(): iterable
    {
        yield 'the check passes' => [self::report('pass', ['APP_SECRET'])];
        yield 'a failing check that names no setting' => [self::report('fail', [])];
        yield 'a failing check from a core without the field' => [self::report('fail', null)];
        yield 'a doctor that could not be read' => ['PHP Fatal error: boom'];
    }

    #[DataProvider('reportsThatBlockNothing')]
    public function testOtherReportsLetTheUpdateThrough(string $doctorOutput): void
    {
        $tester = $this->commandTester($doctorOutput);
        $tester->execute(['--no-composer' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Update completed.', $tester->getDisplay());
        self::assertSame(['sync'], $this->ormCalls->getArrayCopy(), 'the stages after it ran');
    }

    /** Not blocking is not silent: the operator is told the check did not happen. */
    public function testADoctorThatCouldNotBeReadIsSaidLoudly(): void
    {
        $tester = $this->commandTester('PHP Fatal error: boom');
        $tester->execute(['--no-composer' => true]);

        self::assertStringContainsString('WARNING: required settings were NOT checked (APP_ENV=prod)', $tester->getDisplay());
        self::assertStringContainsString('PHP Fatal error: boom', $tester->getDisplay());
    }

    /** A gate that cannot read .env has judged nothing: the update stops, and says which file. */
    public function testAnUnreadableDotEnvEndsTheUpdate(): void
    {
        unlink($this->projectRoot . '/.env');
        mkdir($this->projectRoot . '/.env');

        $tester = $this->commandTester(self::report('pass', ['APP_SECRET']));
        $tester->execute(['--no-composer' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Required settings could not be checked', $tester->getDisplay());
        self::assertStringContainsString('.env exists but cannot be read', $tester->getDisplay());
        self::assertSame([], $this->ormCalls->getArrayCopy());
    }

    /** A core without the field cannot block, and the operator is told the gate did not run. */
    public function testALegacyReportIsSaidLoudly(): void
    {
        $tester = $this->commandTester(self::report('fail', null));
        $tester->execute(['--no-composer' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('the required-settings gate did not run', $tester->getDisplay());
    }

    /**
     * @param list<string>|null $settings null: no `settings` key at all
     */
    private static function report(string $status, ?array $settings): string
    {
        $check = [
            'name' => 'app.secret',
            'package' => 'semitexa/core',
            'status' => $status,
            'message' => 'APP_SECRET is not set: signed components fail with 500 under APP_ENV=prod.',
            'hint' => self::HINT,
        ];
        if ($settings !== null) {
            $check['settings'] = $settings;
        }

        return (string) json_encode([
            'artifact' => 'semitexa.system-doctor/v1',
            'checks' => [$check],
            'healthy' => $status !== 'fail',
        ]);
    }

    private function commandTester(string $doctorOutput): CommandTester
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $this->runJournal = new RunJournalRepository($db);
        $this->ormCalls = new \ArrayObject();

        $gateway = new class($this->ormCalls) implements OrmMigrationGatewayInterface {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private readonly \ArrayObject $calls)
            {
            }

            public function inspect(string $connection): SchemaSyncStatus
            {
                return new SchemaSyncStatus(true, 0, 0, 'ORM in sync (test stub).');
            }

            public function synchronize(string $connection, bool $allowDestructive = false, bool $dryRun = false): SchemaSyncResult
            {
                $this->calls->append('sync');

                return new SchemaSyncResult(0, 0, $dryRun, 'ORM no-op (test stub).');
            }
        };

        $orchestrator = new UpdateOrchestrator(
            runner: new UpdateRunner(
                discovery: new DataPatchDiscovery(new ClassDiscovery()),
                dagBuilder: new DagBuilder(),
                journal: new JournalRepository($db),
                adapter: $db,
                compatibilityChecker: new SchemaCompatibilityChecker(new LiveSchemaInspector($db)),
                semitexaVersion: null,
            ),
            migrationGateway: $gateway,
            connection: 'default',
            projectRoot: $this->projectRoot,
            runJournal: $this->runJournal,
            // No subprocess: the doctor's answer is given, and APP_ENV comes
            // from the fixture's .env whatever this test process runs under.
            requiredSettingsProbe: new DoctorProbe(
                static fn (string $projectRoot): array => ['exitCode' => 1, 'output' => $doctorOutput],
                static fn (string $name): ?string => null,
            ),
        );

        $factory = new class($orchestrator) extends UpdateRunnerFactory {
            public function __construct(private readonly UpdateOrchestrator $stub)
            {
            }

            public function orchestrator(string $connection = 'default', ?string $semitexaVersion = null): UpdateOrchestrator
            {
                return $this->stub;
            }
        };

        $cmd = new UpdateCommand();
        new \ReflectionProperty($cmd, 'runnerFactory')->setValue($cmd, $factory);
        new \ReflectionProperty($cmd, 'missingSkeletonPackages')->setValue($cmd, static fn (): ?array => null);
        $cmd->setName('update');

        return new CommandTester($cmd);
    }
}
