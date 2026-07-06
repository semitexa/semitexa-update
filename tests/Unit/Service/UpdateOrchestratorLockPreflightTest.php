<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Application\Service\LiveSchemaInspector;
use Semitexa\Update\Application\Service\PreflightChecker;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Application\Service\SchemaCompatibilityChecker;
use Semitexa\Update\Application\Service\UpdateLock;
use Semitexa\Update\Application\Service\UpdateOrchestrator;
use Semitexa\Update\Application\Service\UpdateRunner;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Domain\Contract\OrmMigrationGatewayInterface;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\SchemaSyncResult;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;
use Semitexa\Update\Exception\UpdateException;

/**
 * Lock + preflight semantics of the orchestrator:
 *   - a held lock makes a second mutating run fail fast with the holder's identity
 *   - dry-run ignores the lock (read-only)
 *   - the lock is released after the run so the next one proceeds
 *   - a failed preflight aborts before any other stage and is journaled as failed
 */
final class UpdateOrchestratorLockPreflightTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-orch-lock-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/var/lock/semitexa-update.lock');
        @rmdir($this->projectRoot . '/var/lock');
        @rmdir($this->projectRoot . '/var');
        @rmdir($this->projectRoot);
    }

    public function testHeldLockFailsMutatingRunFast(): void
    {
        $holder = new UpdateLock($this->projectRoot);
        self::assertTrue($holder->acquire('cli:other'));

        $orchestrator = $this->orchestrator(lock: new UpdateLock($this->projectRoot));

        try {
            $orchestrator->run(dryRun: false);
            self::fail('Expected UpdateException for a held lock.');
        } catch (UpdateException $e) {
            self::assertStringContainsString('already in progress', $e->getMessage());
            self::assertStringContainsString('cli:other', $e->getMessage());
        } finally {
            $holder->release();
        }
    }

    public function testDryRunIgnoresHeldLock(): void
    {
        $holder = new UpdateLock($this->projectRoot);
        self::assertTrue($holder->acquire('cli:other'));

        $orchestrator = $this->orchestrator(lock: new UpdateLock($this->projectRoot));

        $stages = $orchestrator->run(dryRun: true);
        self::assertNotEmpty($stages);

        $holder->release();
    }

    public function testLockIsReleasedAfterTheRun(): void
    {
        $orchestrator = $this->orchestrator(lock: new UpdateLock($this->projectRoot));
        $orchestrator->run(dryRun: false);

        $after = new UpdateLock($this->projectRoot);
        self::assertTrue($after->acquire('cli:next'), 'Lock must be free after a completed run.');
        $after->release();
    }

    public function testFailedPreflightAbortsBeforeAnyOtherStageAndIsJournaled(): void
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $journal = new RunJournalRepository($db);
        $brokenDb = new SqliteAdapter('sqlite:/nonexistent-' . bin2hex(random_bytes(4)) . '/x.db');
        $orchestrator = $this->orchestrator(
            db: $db,
            runJournal: $journal,
            preflight: new PreflightChecker($brokenDb, $this->projectRoot),
        );

        $stages = $orchestrator->run(dryRun: false);

        self::assertSame('preflight', $stages[0]->name);
        self::assertFalse($stages[0]->isSuccess());
        $stageNames = array_map(static fn ($s) => $s->name, $stages);
        self::assertNotContains('pre-patches', $stageNames);
        self::assertNotContains('orm-sync', $stageNames);

        $records = $journal->findRecent();
        self::assertCount(1, $records);
        self::assertSame(RunOutcome::Failed, $records[0]->outcome);
        self::assertSame('preflight', $records[0]->failedStage);
        self::assertStringContainsString('database', (string) $records[0]->error);
    }

    private function orchestrator(
        ?SqliteAdapter $db = null,
        ?RunJournalRepository $runJournal = null,
        ?UpdateLock $lock = null,
        ?PreflightChecker $preflight = null,
    ): UpdateOrchestrator {
        $db ??= new SqliteAdapter('sqlite::memory:');

        return new UpdateOrchestrator(
            runner: new UpdateRunner(
                discovery: new DataPatchDiscovery(new ClassDiscovery()),
                dagBuilder: new DagBuilder(),
                journal: new JournalRepository($db),
                adapter: $db,
                compatibilityChecker: new SchemaCompatibilityChecker(new LiveSchemaInspector($db)),
                semitexaVersion: null,
            ),
            migrationGateway: $this->gateway(),
            connection: 'default',
            runJournal: $runJournal,
            actor: 'cli:tester',
            updaterVersion: null,
            lock: $lock,
            preflight: $preflight,
        );
    }

    private function gateway(): OrmMigrationGatewayInterface
    {
        return new class implements OrmMigrationGatewayInterface {
            public function inspect(string $connection): SchemaSyncStatus
            {
                return new SchemaSyncStatus(true, 0, 0, 'stub');
            }

            public function synchronize(string $connection, bool $allowDestructive = false, bool $dryRun = false): SchemaSyncResult
            {
                return new SchemaSyncResult(0, 0, $dryRun, 'stub sync');
            }
        };
    }
}
