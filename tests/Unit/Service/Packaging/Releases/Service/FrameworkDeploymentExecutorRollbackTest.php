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
 * Partial-failure semantics of the auto-deploy executor: when a step after a
 * successful `composer update` fails, the pre-update composer state is
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
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{"semitexa/core":"1.0.0"}}');
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
                // Every step succeeds.
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
