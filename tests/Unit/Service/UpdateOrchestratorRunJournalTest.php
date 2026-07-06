<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\DagBuilder;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Application\Service\LiveSchemaInspector;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Application\Service\SchemaCompatibilityChecker;
use Semitexa\Update\Application\Service\UpdateOrchestrator;
use Semitexa\Update\Application\Service\UpdateRunner;
use Semitexa\Update\Discovery\DataPatchDiscovery;
use Semitexa\Update\Domain\Contract\OrmMigrationGatewayInterface;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\SchemaSyncResult;
use Semitexa\Update\Domain\Model\SchemaSyncStatus;
use Semitexa\Update\Exception\UpdateException;

/**
 * Run-journal semantics of the orchestrator:
 *   - a mutating run records exactly one row and appends a `run-journal` stage
 *   - a run with no state change finalizes as noop, with executed schema ops as success
 *   - dry-run records nothing
 *   - an exception mid-run finalizes the row as failed and rethrows
 *   - journal failures degrade to a WARNING stage, never fail the run
 */
final class UpdateOrchestratorRunJournalTest extends TestCase
{
    public function testMutatingRunIsRecordedAsNoopWhenNothingChanged(): void
    {
        $db = $this->sqlite();
        $journal = new RunJournalRepository($db);
        $orchestrator = $this->orchestrator($db, $journal, $this->gateway(executedOperations: 0));

        $stages = $orchestrator->run(dryRun: false);

        $records = $journal->findRecent();
        self::assertCount(1, $records);
        self::assertSame(RunOutcome::Noop, $records[0]->outcome);
        self::assertSame('update', $records[0]->kind);
        self::assertSame('cli:tester', $records[0]->actor);
        self::assertNotNull($records[0]->completedAt);
        self::assertNotSame([], $records[0]->stages);

        $last = $stages[count($stages) - 1];
        self::assertSame('run-journal', $last->name);
        self::assertNotNull($last->message);
        self::assertStringContainsString($records[0]->id, $last->message);
        self::assertStringContainsString('noop', $last->message);
    }

    public function testExecutedSchemaOperationsFinalizeAsSuccess(): void
    {
        $db = $this->sqlite();
        $journal = new RunJournalRepository($db);
        $orchestrator = $this->orchestrator($db, $journal, $this->gateway(executedOperations: 3));

        $orchestrator->run(dryRun: false);

        $records = $journal->findRecent();
        self::assertCount(1, $records);
        self::assertSame(RunOutcome::Success, $records[0]->outcome);
    }

    public function testDryRunRecordsNothing(): void
    {
        $db = $this->sqlite();
        $journal = new RunJournalRepository($db);
        $orchestrator = $this->orchestrator($db, $journal, $this->gateway(executedOperations: 0));

        $stages = $orchestrator->run(dryRun: true);

        self::assertSame([], $journal->findRecent());
        foreach ($stages as $stage) {
            self::assertNotSame('run-journal', $stage->name);
        }
    }

    public function testExceptionMidRunFinalizesAsFailedAndRethrows(): void
    {
        $db = $this->sqlite();
        $journal = new RunJournalRepository($db);
        $gateway = new class implements OrmMigrationGatewayInterface {
            public function inspect(string $connection): SchemaSyncStatus
            {
                return new SchemaSyncStatus(true, 0, 0, 'stub');
            }

            public function synchronize(string $connection, bool $allowDestructive = false, bool $dryRun = false): SchemaSyncResult
            {
                throw new UpdateException('boom: schema sync exploded');
            }
        };
        $orchestrator = $this->orchestrator($db, $journal, $gateway);

        try {
            $orchestrator->run(dryRun: false);
            self::fail('Expected UpdateException to bubble out of run().');
        } catch (UpdateException) {
        }

        $records = $journal->findRecent();
        self::assertCount(1, $records);
        self::assertSame(RunOutcome::Failed, $records[0]->outcome);
        self::assertSame('exception', $records[0]->failedStage);
        self::assertStringContainsString('boom', (string) $records[0]->error);
    }

    public function testJournalBeginFailureDegradesToWarningStage(): void
    {
        $db = $this->sqlite();
        $broken = new RunJournalRepository(new SqliteAdapter('sqlite:/nonexistent-dir-' . bin2hex(random_bytes(4)) . '/x.db'));
        $orchestrator = $this->orchestrator($db, $broken, $this->gateway(executedOperations: 0));

        $stages = $orchestrator->run(dryRun: false);

        $last = $stages[count($stages) - 1];
        self::assertSame('run-journal', $last->name);
        self::assertTrue($last->isSuccess(), 'Journal failure must not fail the run.');
        self::assertNotNull($last->message);
        self::assertStringStartsWith('WARNING', $last->message);
    }

    private function orchestrator(
        SqliteAdapter $db,
        RunJournalRepository $journal,
        OrmMigrationGatewayInterface $gateway,
    ): UpdateOrchestrator {
        return new UpdateOrchestrator(
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
            runJournal: $journal,
            actor: 'cli:tester',
            updaterVersion: null,
        );
    }

    private function gateway(int $executedOperations): OrmMigrationGatewayInterface
    {
        return new class($executedOperations) implements OrmMigrationGatewayInterface {
            public function __construct(private readonly int $executedOperations)
            {
            }

            public function inspect(string $connection): SchemaSyncStatus
            {
                return new SchemaSyncStatus(true, 0, 0, 'stub');
            }

            public function synchronize(string $connection, bool $allowDestructive = false, bool $dryRun = false): SchemaSyncResult
            {
                return new SchemaSyncResult(
                    executedOperations: $this->executedOperations,
                    skippedDestructive: 0,
                    dryRun: $dryRun,
                    summary: 'stub sync',
                );
            }
        };
    }

    private function sqlite(): SqliteAdapter
    {
        return new SqliteAdapter('sqlite::memory:');
    }
}
