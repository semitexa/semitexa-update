<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Packaging\Releases\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentExecutor;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\DeploymentLogWriter;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Application\Service\UpdateLock;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\DeploymentConfig;
use Semitexa\Update\Domain\Model\DeploymentPlan;
use Semitexa\Update\Domain\Model\PackageUpdate;

/**
 * Partial-failure semantics of the auto-deploy executor: when a step after
 * `composer update` changed something fails (or composer itself, after
 * rewriting the lock), the pre-update composer state is
 * restored (files + vendor reinstall), the failure is journaled, and the
 * shared update lock is released.
 */
final class FrameworkDeploymentExecutorRollbackTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-deploy-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/vendor/bin', 0777, true);
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{"semitexa/core":">=1.0.0"}}');
        file_put_contents($this->projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"1.0.0"}]}');
        file_put_contents($this->projectRoot . '/vendor/bin/semitexa', "#!/bin/sh\nexit 0\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRoot));
    }

    public function testFailureAfterComposerUpdateRestoresStateAndJournalsFailed(): void
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $journal = new RunJournalRepository($db);

        $runner = function (string $command, string $projectRoot): void {
            if (str_contains($command, ' update ')) {
                // Simulate composer update mutating the dependency state.
                file_put_contents($projectRoot . '/composer.json', '{"require":{"semitexa/core":"2.0.0"}}');
                file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');
                return;
            }
            if (str_contains($command, 'orm:sync')) {
                throw new \RuntimeException('orm:sync exploded');
            }
            // composer install (rollback), cache:clear, restart: succeed.
        };

        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            $journal,
            new UpdateLock($this->projectRoot),
            \Closure::fromCallable($runner),
        );

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('orm:sync exploded', (string) $result['reason']);
        self::assertStringStartsWith('performed', (string) $result['rollback']);
        self::assertStringContainsString(
            '1.0.0',
            (string) file_get_contents($this->projectRoot . '/composer.json'),
            'composer.json must be restored to the pre-update pin.',
        );

        $records = $journal->findRecent();
        self::assertCount(1, $records);
        self::assertSame(RunOutcome::Failed, $records[0]->outcome);
        self::assertSame(
            ['semitexa/core' => ['from' => '1.0.0', 'to' => '2.0.0']],
            $records[0]->packageDeltas,
        );

        $lock = new UpdateLock($this->projectRoot);
        self::assertTrue($lock->acquire('test'), 'Lock must be released after a failed deploy.');
        $lock->release();
    }

    public function testSuccessfulDeployJournalsSuccessWithDeltas(): void
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $journal = new RunJournalRepository($db);

        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            $journal,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot): void {
                // Every step succeeds; composer installs 2.0.0.
                if (str_contains($command, ' update ')) {
                    file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');
                    @mkdir($projectRoot . '/vendor/composer', 0777, true);
                    file_put_contents($projectRoot . '/vendor/composer/installed.json', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}],"dev":false}');
                }
            },
        );

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertSame('updated', $result['status']);
        self::assertArrayNotHasKey('rollback', $result);
        self::assertStringContainsString('recorded run', (string) $result['run_journal']);

        $records = $journal->findRecent();
        self::assertCount(1, $records);
        self::assertSame(RunOutcome::Success, $records[0]->outcome);
        self::assertSame('auto-deploy', $records[0]->kind);
        self::assertSame(['semitexa/core' => ['from' => '1.0.0', 'to' => '2.0.0']], $records[0]->packageDeltas);
    }

    /** A constraint held the package back: the journal says it did not move. */
    public function testAPackageComposerHeldBackIsNotJournalledAsMoved(): void
    {
        $journal = new RunJournalRepository(new SqliteAdapter('sqlite::memory:'));
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            $journal,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot): void {
                if (str_contains($command, ' update ')) {
                    // A third-party package moved; semitexa/core stayed.
                    file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"1.0.0"},{"name":"psr/log","version":"3.0.2"}]}');
                    @mkdir($projectRoot . '/vendor/composer', 0777, true);
                    file_put_contents($projectRoot . '/vendor/composer/installed.json', '{"packages":[{"name":"semitexa/core","version":"v1.0.0"},{"name":"psr/log","version":"3.0.2"}],"dev":false}');
                }
            },
        );

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertSame('updated', $result['status']);
        self::assertSame('1.0.0', $result['updated_packages'][0]['deployed_version']);
        self::assertSame([], $journal->findRecent()[0]->packageDeltas);
    }

    /**
     * A project made from semitexa/ultimate pins every semitexa/* exactly:
     * composer could move nothing, yet every tick ran it and the run said
     * "updated" with versions that were never installed.
     */
    public function testAPackagePinnedAtItsInstalledVersionIsANoopWithoutComposer(): void
    {
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{"semitexa/core":"1.0.0"}}');
        $journal = new RunJournalRepository(new SqliteAdapter('sqlite::memory:'));
        $ran = [];
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            $journal,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot) use (&$ran): void {
                $ran[] = $command;
            },
        );

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertSame('noop', $result['status']);
        self::assertStringContainsString('pins semitexa/core', (string) $result['reason']);
        self::assertStringContainsString('bin/semitexa update', (string) $result['reason']);
        self::assertSame([], $ran, 'composer is not run when nothing can move');
        self::assertSame([], $journal->findRecent(), 'a noop tick is not a run');
    }

    /**
     * Composer ran and moved nothing (a pinned package the newer one needs
     * held it back): taken for an update, the systemd wrapper restarted the
     * app every tick.
     */
    public function testComposerThatMovedNothingIsANoopWithoutSyncOrRestart(): void
    {
        mkdir($this->projectRoot . '/vendor/composer', 0777, true);
        file_put_contents($this->projectRoot . '/vendor/composer/installed.json', '{"packages":[{"name":"semitexa/core","version":"1.0.0"}],"dev":false}');
        $journal = new RunJournalRepository(new SqliteAdapter('sqlite::memory:'));
        $ran = [];
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            $journal,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot) use (&$ran): void {
                $ran[] = $command; // composer resolves to what is there
            },
        );

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertSame('noop', $result['status']);
        self::assertFalse($result['restart_required']);
        self::assertStringContainsString('composer installed none of them', (string) $result['reason']);
        self::assertCount(1, $ran, 'composer only: no orm:sync, cache:clear or restart');
        $record = $journal->findRecent()[0];
        self::assertSame(RunOutcome::Noop, $record->outcome);
        self::assertSame([], $record->packageDeltas);
    }

    /** A composer that failed half-way left the lock and vendor/ as it had them. */
    public function testAComposerUpdateThatFailsIsRolledBackToo(): void
    {
        $ran = [];
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            null,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot) use (&$ran): void {
                $ran[] = $command;
                if (str_contains($command, ' update ')) {
                    file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');
                    throw new \RuntimeException('composer: network unreachable');
                }
            },
        );

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertSame('failed', $result['status']);
        self::assertStringStartsWith('performed', (string) $result['rollback']);
        self::assertStringContainsString('1.0.0', (string) file_get_contents($this->projectRoot . '/composer.lock'));
        self::assertTrue((bool) array_filter($ran, static fn (string $c): bool => str_contains($c, ' install ')), 'vendor/ reinstalled from the restored lock');
    }

    /**
     * Inside the app container nothing is restarted, but cache:clear has
     * reloaded the workers on the new vendor/: the health check still runs,
     * and it is what rolls a broken release back.
     */
    public function testTheHealthCheckRunsWithoutARestartAndRollsBack(): void
    {
        $plan = $this->plan();
        $plan = new DeploymentPlan(
            config: new DeploymentConfig(enabled: true, channel: 'stable', sourceMode: 'packagist', healthcheckUrl: 'http://127.0.0.1:1/never', privateRepositoryUrl: null, restartCommand: null),
            installedPackages: $plan->installedPackages,
            packageUpdates: $plan->packageUpdates,
            privateLatestVersion: null,
            selectedVersion: '2.0.0',
            updateAvailable: true,
            reason: 'test',
        );
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            null,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot): void {
                if (str_contains($command, ' update ')) {
                    file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');
                }
            },
        );

        $result = $executor->execute($this->projectRoot, $plan);

        self::assertSame('failed', $result['status']);
        self::assertStringStartsWith('performed', (string) $result['rollback']);
    }

    /**
     * Packagist unreachable, a token expired: composer fails before it
     * changes anything. A rollback there restarted the app every tick.
     */
    public function testAComposerThatFailedBeforeChangingTheLockIsNotRolledBack(): void
    {
        $ran = [];
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            null,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot) use (&$ran): void {
                $ran[] = $command;
                if (str_contains($command, ' update ')) {
                    throw new \RuntimeException('composer: could not resolve host');
                }
            },
        );

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertSame('failed', $result['status']);
        self::assertStringStartsWith('not needed', (string) $result['rollback']);
        self::assertCount(1, $ran, 'no composer install, no restart');
    }

    private function plan(): DeploymentPlan
    {
        return new DeploymentPlan(
            config: new DeploymentConfig(
                enabled: true,
                channel: 'stable',
                sourceMode: 'packagist',
                healthcheckUrl: null,
                privateRepositoryUrl: null,
                restartCommand: null,
            ),
            installedPackages: ['semitexa/core' => '1.0.0'],
            packageUpdates: [
                new PackageUpdate(
                    packageName: 'semitexa/core',
                    installedVersion: '1.0.0',
                    latestVersion: '2.0.0',
                    source: 'packagist',
                ),
            ],
            privateLatestVersion: null,
            selectedVersion: '2.0.0',
            updateAvailable: true,
            reason: 'test',
        );
    }
}
