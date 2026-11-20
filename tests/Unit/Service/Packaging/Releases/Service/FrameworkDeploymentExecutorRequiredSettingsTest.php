<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Packaging\Releases\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentExecutor;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\DeploymentLogWriter;
use Semitexa\Update\Application\Service\RequiredSettings\DoctorProbe;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Application\Service\UpdateLock;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\DeploymentConfig;
use Semitexa\Update\Domain\Model\DeploymentPlan;
use Semitexa\Update\Domain\Model\PackageUpdate;

/**
 * The auto-deploy never runs `bin/semitexa update`, so the gate on required
 * settings stands here too: a release whose code needs a setting .env lacks
 * is rolled back before orm:sync, and stays deployable.
 */
final class FrameworkDeploymentExecutorRequiredSettingsTest extends TestCase
{
    private string $projectRoot;
    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-deploy-settings-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/vendor/bin', 0777, true);
        file_put_contents($this->projectRoot . '/.env', "APP_ENV=prod\n");
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{"semitexa/core":">=1.0.0"}}');
        file_put_contents($this->projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"1.0.0"}]}');
        file_put_contents($this->projectRoot . '/vendor/bin/semitexa', "#!/bin/sh\nexit 0\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRoot));
    }

    public function testAMissingRequiredSettingRollsTheDeployBackAndRejectsNothing(): void
    {
        $journal = new RunJournalRepository(new SqliteAdapter('sqlite::memory:'));
        $executor = $this->executor(self::doctorReport('fail'), $journal);

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('APP_SECRET', self::text($result, 'reason'));
        self::assertStringContainsString('APP_ENV=prod', self::text($result, 'reason'));
        self::assertStringContainsString('openssl rand -hex 32', self::text($result, 'reason'));
        self::assertSame('missing: APP_SECRET', $result['required_settings']);

        self::assertStringStartsWith('performed', self::text($result, 'rollback'));
        self::assertStringContainsString('1.0.0', (string) file_get_contents($this->projectRoot . '/composer.lock'), 'the lock is the one before the deploy');
        self::assertSame(['update', 'install', 'restart'], $this->steps(), 'composer, then the rollback: orm:sync and cache:clear never ran');

        // The release is sound: only .env is not.
        self::assertArrayNotHasKey('rejected', $result);
        self::assertFileDoesNotExist($this->projectRoot . '/var/update/rollback/rejected.json');

        $record = $journal->findRecent()[0];
        self::assertSame(RunOutcome::Failed, $record->outcome);
        self::assertStringContainsString('APP_SECRET', (string) $record->error);

        self::assertStringStartsWith('recorded', self::text($result, 'blocked'));

        // The next tick offers the same release, and with the setting there it goes in.
        file_put_contents($this->projectRoot . '/.env', "APP_ENV=prod\nAPP_SECRET=" . str_repeat('a', 64) . "\n");
        $this->ran = [];
        $second = $this->executor(self::doctorReport('pass'), $journal)->execute($this->projectRoot, $this->plan());

        self::assertSame('updated', $second['status'], self::text($second, 'reason'));
        self::assertSame('ok (APP_ENV=prod)', $second['required_settings']);
        self::assertSame(['update', 'orm:sync', 'cache:clear', 'restart'], $this->steps());
        self::assertFileDoesNotExist($this->projectRoot . '/var/update/rollback/blocked.json', 'a deploy that went in leaves no marker');
    }

    /**
     * Unmarked, every tick installed the release, rolled it back and restarted
     * the app, for as long as nobody read the log.
     */
    public function testTheNextTickWaitsWhileTheReleaseAndDotEnvAreTheSame(): void
    {
        $journal = new RunJournalRepository(new SqliteAdapter('sqlite::memory:'));
        $this->executor(self::doctorReport('fail'), $journal)->execute($this->projectRoot, $this->plan());
        $this->ran = [];

        $second = $this->executor(self::doctorReport('fail'), $journal)->execute($this->projectRoot, $this->plan());

        self::assertSame('noop', $second['status']);
        self::assertSame([], $this->ran, 'no composer, no rollback, no restart');
        self::assertStringContainsString('APP_SECRET', self::text($second, 'reason'));
        self::assertStringContainsString('blocked.json', self::text($second, 'reason'));
        self::assertSame('missing: APP_SECRET', $second['required_settings']);
        self::assertCount(1, $journal->findRecent(), 'a waiting tick is not a run');
    }

    public function testAChangedDotEnvIsTriedAgain(): void
    {
        $this->executor(self::doctorReport('fail'), null)->execute($this->projectRoot, $this->plan());
        $this->ran = [];
        // Any change: whether it is the right one is the doctor's to say.
        file_put_contents($this->projectRoot . '/.env', "APP_ENV=prod\nAPP_NAME=changed\n");

        $second = $this->executor(self::doctorReport('fail'), null)->execute($this->projectRoot, $this->plan());

        self::assertSame('failed', $second['status']);
        self::assertSame(['update', 'install', 'restart'], $this->steps(), 'tried, and still missing');
        self::assertStringStartsWith('recorded', self::text($second, 'blocked'));

        $this->ran = [];
        $third = $this->executor(self::doctorReport('fail'), null)->execute($this->projectRoot, $this->plan());
        self::assertSame(['noop', []], [$third['status'], $this->ran], 'and the marker holds for the new .env');
    }

    /**
     * A marker that is there and cannot be read was taken for no marker and
     * deleted: the tick installed, rolled back and restarted again. It fails
     * the tick before composer, and stays for whoever has to look at it.
     */
    public function testAnUnreadableMarkerFailsTheTickAndStays(): void
    {
        $marker = $this->projectRoot . '/var/update/rollback/blocked.json';
        foreach (['a directory in its place' => null, 'not JSON' => 'half a mar'] as $case => $contents) {
            exec('rm -rf ' . escapeshellarg($marker));
            @mkdir(dirname($marker), 0777, true);
            $contents === null ? mkdir($marker) : file_put_contents($marker, $contents);
            $this->ran = [];

            $result = $this->executor(self::doctorReport('pass'), null)->execute($this->projectRoot, $this->plan());

            self::assertSame('failed', $result['status'], $case);
            self::assertStringContainsString('blocked.json exists but cannot be read', self::text($result, 'reason'), $case);
            self::assertSame([], $this->ran, $case . ': nothing installed, nothing restarted');
            self::assertFileExists($marker, $case . ': the marker is not deleted');
        }
    }

    /**
     * An unreadable .env hashed as 'absent', the hash of no .env at all: a
     * marker recorded without a .env kept holding. It has no hash; the tick
     * fails before composer and names the file.
     */
    public function testAnUnreadableDotEnvFailsTheTickBeforeComposer(): void
    {
        unlink($this->projectRoot . '/.env');
        // Blocked with no .env at all: the marker records 'absent'.
        $first = $this->executor(self::doctorReport('fail'), null)->execute($this->projectRoot, $this->plan());
        self::assertStringStartsWith('recorded', self::text($first, 'blocked'));
        mkdir($this->projectRoot . '/.env');
        $this->ran = [];

        $second = $this->executor(self::doctorReport('fail'), null)->execute($this->projectRoot, $this->plan());

        self::assertSame('failed', $second['status'], 'not the noop of a marker that still matches');
        self::assertStringContainsString('.env exists but cannot be read', self::text($second, 'reason'));
        self::assertSame([], $this->ran);
        self::assertArrayNotHasKey('rollback', $second);
    }

    public function testANewerReleaseIsTriedAgain(): void
    {
        $this->executor(self::doctorReport('fail'), null)->execute($this->projectRoot, $this->plan());
        $this->ran = [];

        $second = $this->executor(self::doctorReport('pass'), null)->execute($this->projectRoot, $this->plan('3.0.0'));

        self::assertSame('updated', $second['status'], self::text($second, 'reason'));
        self::assertSame(['update', 'orm:sync', 'cache:clear', 'restart'], $this->steps());
    }

    /** A doctor that cannot be read must not cost a deploy: said in the result, and the deploy goes on. */
    public function testADoctorThatCannotBeReadDoesNotFailTheDeploy(): void
    {
        $result = $this->executor('PHP Fatal error: boom', null)->execute($this->projectRoot, $this->plan());

        self::assertSame('updated', $result['status']);
        self::assertStringStartsWith('WARNING: not checked (APP_ENV=prod)', self::text($result, 'required_settings'));
        self::assertContains('orm:sync', $this->steps());
    }

    private function executor(string $doctorOutput, ?RunJournalRepository $journal): FrameworkDeploymentExecutor
    {
        return new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            $journal,
            new UpdateLock($this->projectRoot),
            function (string $command, string $projectRoot): void {
                $this->ran[] = $command;
                if (str_contains($command, ' update ')) {
                    file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');
                    @mkdir($projectRoot . '/vendor/composer', 0777, true);
                    file_put_contents($projectRoot . '/vendor/composer/installed.json', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}],"dev":false}');
                }
                if (str_contains($command, ' install ')) {
                    file_put_contents($projectRoot . '/vendor/composer/installed.json', '{"packages":[{"name":"semitexa/core","version":"1.0.0"}],"dev":false}');
                }
            },
            doctorProbe: new DoctorProbe(
                static fn (string $projectRoot): array => ['exitCode' => 1, 'output' => $doctorOutput],
                static fn (string $name): ?string => null,
            ),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function text(array $result, string $key): string
    {
        $value = $result[$key] ?? null;
        self::assertIsString($value, $key . ' is in the result');

        return $value;
    }

    /** @return list<string> the commands run, by what they were */
    private function steps(): array
    {
        return array_map(static fn (string $command): string => match (true) {
            str_contains($command, ' update ') => 'update',
            str_contains($command, ' install ') => 'install',
            str_contains($command, 'orm:sync') => 'orm:sync',
            str_contains($command, 'cache:clear') => 'cache:clear',
            $command === 'restart-the-stack' => 'restart',
            default => $command,
        }, $this->ran);
    }

    private static function doctorReport(string $status): string
    {
        return (string) json_encode([
            'artifact' => 'semitexa.system-doctor/v1',
            'checks' => [[
                'name' => 'app.secret',
                'package' => 'semitexa/core',
                'status' => $status,
                'message' => 'APP_SECRET is not set.',
                'hint' => 'Set APP_SECRET in .env to a long random value: openssl rand -hex 32',
                'settings' => ['APP_SECRET'],
            ]],
            'healthy' => $status !== 'fail',
        ]);
    }

    private function plan(string $latest = '2.0.0'): DeploymentPlan
    {
        return new DeploymentPlan(
            config: new DeploymentConfig(
                enabled: true,
                channel: 'stable',
                sourceMode: 'packagist',
                healthcheckUrl: null,
                privateRepositoryUrl: null,
                restartCommand: 'restart-the-stack',
            ),
            installedPackages: ['semitexa/core' => '1.0.0'],
            packageUpdates: [
                new PackageUpdate(packageName: 'semitexa/core', installedVersion: '1.0.0', latestVersion: $latest, source: 'packagist'),
            ],
            privateLatestVersion: null,
            selectedVersion: $latest,
            updateAvailable: true,
            reason: 'test',
        );
    }
}
