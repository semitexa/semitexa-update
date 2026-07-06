<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Read paths (plan / status / dry-run surfaces) must never issue DDL: on a
 * fresh database the journal tables simply do not exist and reads return
 * empty. Only the first mutating call creates the tables.
 */
final class JournalReadOnlyPathsTest extends TestCase
{
    public function testPatchJournalReadsDoNotCreateTheTable(): void
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $repo = new JournalRepository($db);

        self::assertSame([], $repo->findAllByIdentity());
        self::assertNull($repo->findByIdentity('acme/app', 'p1'));
        self::assertFalse($this->tableExists($db, JournalRepository::TABLE));
    }

    public function testPatchJournalWriteCreatesTheTable(): void
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $repo = new JournalRepository($db);

        $repo->markPending('acme/app', 'p1', 'Acme\\P1', UpdatePhase::Apply, 'sum', null);

        self::assertTrue($this->tableExists($db, JournalRepository::TABLE));
        self::assertNotNull($repo->findByIdentity('acme/app', 'p1'));
    }

    public function testRunJournalReadsDoNotCreateTheTable(): void
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $repo = new RunJournalRepository($db);

        self::assertSame([], $repo->findRecent());
        self::assertNull($repo->find('nope'));
        self::assertFalse($this->tableExists($db, RunJournalRepository::TABLE));
    }

    public function testRunJournalBeginCreatesTheTable(): void
    {
        $db = new SqliteAdapter('sqlite::memory:');
        $repo = new RunJournalRepository($db);

        $id = $repo->begin(RunJournalRepository::KIND_UPDATE, 'cli:tester', null);

        self::assertTrue($this->tableExists($db, RunJournalRepository::TABLE));
        self::assertNotNull($repo->find($id));
    }

    private function tableExists(SqliteAdapter $db, string $table): bool
    {
        $rows = $db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'"
        )->fetchAll();
        return $rows !== [];
    }
}
