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

        $result = $executor->execute($this->projectRoot, $this->plan(restartCommand: 'restart-the-stack'));

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

        $result = $executor->execute($this->projectRoot, $this->plan(restartCommand: 'restart-the-stack'));

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
        $ran = [];
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            null,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot) use (&$ran): void {
                $ran[] = $command;
                if (str_contains($command, ' update ')) {
                    file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');
                }
            },
        );

        $result = $executor->execute($this->projectRoot, $plan);

        self::assertSame('failed', $result['status']);
        // Nothing restarts here: it said "performed" while the workers kept
        // the broken release. They are reloaded onto the restored vendor/,
        // and the restart is left owed for the wrapper on the host.
        self::assertStringStartsWith('partial', (string) $result['rollback']);
        self::assertTrue($result['restart_required']);
        $clears = array_values(array_filter($ran, static fn (string $c): bool => str_contains($c, 'cache:clear')));
        self::assertCount(2, $clears, 'once onto the new vendor/, once onto the restored one');
        $install = array_search(true, array_map(static fn (string $c): bool => str_contains($c, ' install '), $ran), true);
        self::assertIsInt($install);
        $clearAt = array_keys(array_filter($ran, static fn (string $c): bool => str_contains($c, 'cache:clear')));
        self::assertGreaterThan($install, $clearAt[1], 'the second cache:clear follows the reinstall');
    }

    /**
     * A rollback whose composer install failed leaves vendor/ half-written:
     * restarting onto it took the site from the old code still in memory to
     * none. It owes no restart; the operator steps in.
     */
    public function testAFailedRollbackOwesNoRestart(): void
    {
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            null,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot): void {
                if (str_contains($command, ' update ')) {
                    file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');
                    throw new \RuntimeException('composer: disk full');
                }
                if (str_contains($command, ' install ')) {
                    throw new \RuntimeException('composer: disk full');
                }
            },
        );

        $result = $executor->execute($this->projectRoot, $this->plan());

        self::assertStringStartsWith('FAILED', (string) $result['rollback']);
        self::assertFalse($result['restart_required']);
    }

    /**
     * A lock already ahead of vendor/, or --no-dev removing packages: the
     * install half-changes vendor/ and leaves the lock as it was. Judged by
     * the lock alone, that was "not needed".
     */
    public function testAComposerThatChangedVendorButNotTheLockIsRolledBack(): void
    {
        mkdir($this->projectRoot . '/vendor/composer', 0777, true);
        file_put_contents($this->projectRoot . '/vendor/composer/installed.json', '{"packages":[{"name":"semitexa/core","version":"1.0.0"}]}');
        $ran = [];
        $executor = new FrameworkDeploymentExecutor(
            new DeploymentLogWriter(),
            null,
            new UpdateLock($this->projectRoot),
            static function (string $command, string $projectRoot) use (&$ran): void {
                $ran[] = $command;
                if (str_contains($command, ' update ')) {
                    file_put_contents($projectRoot . '/vendor/composer/installed.json', '{"packages":[]}');
                    throw new \RuntimeException('composer: disk full');
                }
            },
        );

        $result = $executor->execute($this->projectRoot, $this->plan(restartCommand: 'restart-the-stack'));

        self::assertSame('failed', $result['status']);
        self::assertStringStartsWith('performed', (string) $result['rollback']);
        self::assertTrue((bool) array_filter($ran, static fn (string $c): bool => str_contains($c, ' install ')), 'vendor/ reinstalled from the lock');
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

    /**
     * The wrapper's health check after its restart is the one a broken
     * release fails, and by then the deploy process and its in-memory
     * snapshot were gone: nothing could go back. The deploy keeps it on disk.
     */
    public function testAHealthyDeployKeepsTheStateItReplacedForALaterRollback(): void
    {
        $ran = [];
        $deploy = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), self::composerInstalling2($ran));

        $result = $deploy->execute($this->projectRoot, $this->plan());

        self::assertSame('updated', $result['status']);
        self::assertSame('kept', $result['rollback_snapshot']);

        $ran = [];
        $rollback = (new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (string $command) use (&$ran): void {
            $ran[] = $command;
        }))->rollbackLastDeploy($this->projectRoot);

        self::assertSame('rolled_back', $rollback['status'], (string) $rollback['reason']);
        self::assertTrue($rollback['restart_required'], 'nothing restarts here: the wrapper owes the restart');
        self::assertStringContainsString('1.0.0', (string) file_get_contents($this->projectRoot . '/composer.lock'));
        self::assertStringContainsString('>=1.0.0', (string) file_get_contents($this->projectRoot . '/composer.json'));
        self::assertTrue((bool) array_filter($ran, static fn (string $c): bool => str_contains($c, ' install ')), 'vendor/ reinstalled from the restored lock');

        $again = (new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (): void {}))
            ->rollbackLastDeploy($this->projectRoot);
        self::assertSame('nothing', $again['status'], 'a rollback is used once');
    }

    /**
     * vendor/ back on 1.0.0 makes 2.0.0 "newer" again: every tick deployed
     * it, restarted, failed the wrapper's check and rolled back.
     */
    public function testTheReleasesARollbackBackedOutAreNotDeployedAgain(): void
    {
        $ran = [];
        $deploy = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), self::composerInstalling2($ran));
        self::assertSame('updated', $deploy->execute($this->projectRoot, $this->plan())['status']);
        $rollback = (new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (): void {}))
            ->rollbackLastDeploy($this->projectRoot);
        self::assertSame('rolled_back', $rollback['status']);

        $ran = [];
        $result = $deploy->execute($this->projectRoot, $this->plan());

        self::assertSame('noop', $result['status']);
        self::assertStringContainsString('rolled back', (string) $result['reason']);
        self::assertSame([], $ran, 'composer not run');

        self::assertSame('updated', $deploy->execute($this->projectRoot, $this->plan(latest: '2.0.1'))['status'], 'a newer release is deployed');
    }

    /**
     * REGRESSION (review). Inside the app container nothing restarts, and the
     * deploy's own health check is the one a broken release fails: it rolled
     * back and recorded nothing, so every tick deployed the same release,
     * failed and rolled back again.
     */
    public function testAReleaseThatFailedTheHealthCheckInProcessIsNotDeployedAgain(): void
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
        $ran = [];
        $deploy = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), self::composerInstalling2($ran));

        $failed = $deploy->execute($this->projectRoot, $plan);
        self::assertSame('failed', $failed['status']);
        self::assertSame('recorded', $failed['rejected']);
        $rejected = json_decode((string) file_get_contents($this->projectRoot . '/var/update/rollback/rejected.json'), true);
        self::assertIsArray($rejected);
        self::assertSame(['semitexa/core' => '2.0.0'], $rejected['installed']);

        $ran = [];
        $again = $deploy->execute($this->projectRoot, $plan);

        self::assertSame('noop', $again['status']);
        self::assertSame([], $ran, 'composer not run');
    }

    /** A step that may pass on the next tick (orm:sync with the database down) rejects nothing. */
    public function testAFailureOtherThanTheHealthCheckRejectsNothing(): void
    {
        $ran = [];
        $installing = self::composerInstalling2($ran);
        $deploy = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (string $command, string $projectRoot) use ($installing): void {
            $installing($command, $projectRoot);
            if (str_contains($command, 'orm:sync')) {
                throw new \RuntimeException('database unreachable');
            }
        });

        $result = $deploy->execute($this->projectRoot, $this->plan());

        self::assertSame('failed', $result['status']);
        self::assertArrayNotHasKey('rejected', $result);
        self::assertFileDoesNotExist($this->projectRoot . '/var/update/rollback/rejected.json');
    }

    /** A Packagist lookup that timed out leaves a package off the plan; composer still installs it. */
    public function testARejectionHoldsWhenThePlanListsFewerPackages(): void
    {
        $ran = [];
        $deploy = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (string $command, string $projectRoot) use (&$ran): void {
            $ran[] = $command;
            if (str_contains($command, ' update ')) {
                file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"},{"name":"semitexa/orm","version":"2.0.0"}]}');
                @mkdir($projectRoot . '/vendor/composer', 0777, true);
                file_put_contents($projectRoot . '/vendor/composer/installed.json', '{"packages":[{"name":"semitexa/core","version":"2.0.0"},{"name":"semitexa/orm","version":"2.0.0"}],"dev":false}');
            }
        });
        self::assertSame('updated', $deploy->execute($this->projectRoot, $this->plan())['status']);
        (new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (): void {}))
            ->rollbackLastDeploy($this->projectRoot);

        $ran = [];
        self::assertSame('noop', $deploy->execute($this->projectRoot, $this->plan())['status']);
        self::assertSame([], $ran);

        unlink($this->projectRoot . '/var/update/rollback/rejected.json');
        self::assertSame('updated', $deploy->execute($this->projectRoot, $this->plan())['status'], 'deleting the marker tries them again');
    }

    /** Anything that changed composer.lock since: putting the old one back would undo that too. */
    public function testALockChangedSinceTheDeployIsNotRolledBack(): void
    {
        $ran = [];
        $deploy = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), self::composerInstalling2($ran));
        self::assertSame('updated', $deploy->execute($this->projectRoot, $this->plan())['status']);
        file_put_contents($this->projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"3.0.0"}]}');

        $ran = [];
        $result = (new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (string $command) use (&$ran): void {
            $ran[] = $command;
        }))->rollbackLastDeploy($this->projectRoot);

        self::assertSame('nothing', $result['status']);
        self::assertSame([], $ran);
        self::assertStringContainsString('3.0.0', (string) file_get_contents($this->projectRoot . '/composer.lock'));
    }

    /** A kept lock that went missing must not read as "there was none" and delete the project's. */
    public function testAKeptLockThatWentMissingRollsNothingBack(): void
    {
        $ran = [];
        $deploy = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), self::composerInstalling2($ran));
        self::assertSame('updated', $deploy->execute($this->projectRoot, $this->plan())['status']);
        unlink($this->projectRoot . '/var/update/rollback/composer.lock');

        $result = (new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (): void {}))
            ->rollbackLastDeploy($this->projectRoot);

        self::assertSame('nothing', $result['status']);
        self::assertFileExists($this->projectRoot . '/composer.lock');
    }

    public function testARollbackWhileAnUpdateRunsIsRefused(): void
    {
        $ran = [];
        $deploy = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), self::composerInstalling2($ran));
        self::assertSame('updated', $deploy->execute($this->projectRoot, $this->plan())['status']);
        $held = new UpdateLock($this->projectRoot);
        self::assertTrue($held->acquire('test'));

        try {
            $result = (new FrameworkDeploymentExecutor(new DeploymentLogWriter(), null, new UpdateLock($this->projectRoot), static function (): void {}))
                ->rollbackLastDeploy($this->projectRoot);
        } finally {
            $held->release();
        }

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('2.0.0', (string) file_get_contents($this->projectRoot . '/composer.lock'));
    }

    /** @param list<string> $ran */
    private static function composerInstalling2(array &$ran): \Closure
    {
        return static function (string $command, string $projectRoot) use (&$ran): void {
            $ran[] = $command;
            if (str_contains($command, ' update ')) {
                file_put_contents($projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');
                @mkdir($projectRoot . '/vendor/composer', 0777, true);
                file_put_contents($projectRoot . '/vendor/composer/installed.json', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}],"dev":false}');
            }
        };
    }

    private function plan(?string $restartCommand = null, string $latest = '2.0.0'): DeploymentPlan
    {
        return new DeploymentPlan(
            config: new DeploymentConfig(
                enabled: true,
                channel: 'stable',
                sourceMode: 'packagist',
                healthcheckUrl: null,
                privateRepositoryUrl: null,
                restartCommand: $restartCommand,
            ),
            installedPackages: ['semitexa/core' => '1.0.0'],
            packageUpdates: [
                new PackageUpdate(
                    packageName: 'semitexa/core',
                    installedVersion: '1.0.0',
                    latestVersion: $latest,
                    source: 'packagist',
                ),
            ],
            privateLatestVersion: null,
            selectedVersion: $latest,
            updateAvailable: true,
            reason: 'test',
        );
    }
}
