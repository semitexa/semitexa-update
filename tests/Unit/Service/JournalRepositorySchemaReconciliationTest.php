<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\JournalRepository;
use Semitexa\Update\Domain\Enum\PatchStatus;
use Semitexa\Update\Domain\Enum\UpdatePhase;

/**
 * Regression: downstream projects on the pre-2026.05 schema had a single
 * `step_fqcn` identity column and crashed with "Unknown column 'module'"
 * the moment they upgraded to the (module, patch_id) journal. The
 * repository must reconcile the legacy schema on first call and preserve
 * already-applied rows so they are not rerun.
 */
final class JournalRepositorySchemaReconciliationTest extends TestCase
{
    public function testFreshInstallCreatesNewSchema(): void
    {
        $db = $this->sqlite();
        $repo = new JournalRepository($db);

        $repo->ensureSchema();

        $columns = $this->columnNames($db);
        self::assertContains('module', $columns);
        self::assertContains('patch_id', $columns);
        self::assertContains('patch_fqcn', $columns);
        self::assertContains('semitexa_version', $columns);
        self::assertContains('applied_db_state_hash', $columns);
        self::assertNotContains('step_fqcn', $columns);
    }

    public function testLegacySchemaIsReconciledAndPreservesAppliedRows(): void
    {
        $db = $this->sqlite();
        $this->createLegacyTable($db);
        $db->query(
            "INSERT INTO " . JournalRepository::TABLE
            . " (id, step_fqcn, phase, checksum, status, started_at, completed_at, duration_ms, error)"
            . " VALUES ('row-1', 'Acme\\Patch\\Renamed', 'apply', 'sum1', '" . PatchStatus::Applied->value . "',"
            . "         '2026-01-01T00:00:00.000000Z', '2026-01-01T00:00:01.000000Z', 1000, NULL)"
        );

        (new JournalRepository($db))->ensureSchema();

        $columns = $this->columnNames($db);
        self::assertContains('module', $columns);
        self::assertContains('patch_id', $columns);
        self::assertNotContains('step_fqcn', $columns);

        $rows = $db->query('SELECT * FROM ' . JournalRepository::TABLE)->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('row-1', $rows[0]['id']);
        self::assertSame('legacy', $rows[0]['module']);
        self::assertSame('Acme\\Patch\\Renamed', $rows[0]['patch_id']);
        self::assertSame('Acme\\Patch\\Renamed', $rows[0]['patch_fqcn']);
        self::assertSame(PatchStatus::Applied->value, $rows[0]['status']);
    }

    public function testReconciliationIsIdempotent(): void
    {
        $db = $this->sqlite();
        $this->createLegacyTable($db);

        $repoOne = new JournalRepository($db);
        $repoOne->ensureSchema();

        $repoTwo = new JournalRepository($db);
        $repoTwo->ensureSchema();

        $entries = $repoTwo->findAllByIdentity();
        self::assertSame([], $entries);
    }

    public function testFindAllByIdentityWorksAfterReconciliation(): void
    {
        $db = $this->sqlite();
        $this->createLegacyTable($db);
        $db->query(
            "INSERT INTO " . JournalRepository::TABLE
            . " (id, step_fqcn, phase, checksum, status, started_at)"
            . " VALUES ('row-a', 'App\\Old\\Step', 'apply', 'sum1', '" . PatchStatus::Applied->value . "', '2026-01-01T00:00:00.000000Z')"
        );

        $repo = new JournalRepository($db);

        $entries = $repo->findAllByIdentity();
        self::assertArrayHasKey('legacy:App\\Old\\Step', $entries);
        self::assertSame(PatchStatus::Applied, $entries['legacy:App\\Old\\Step']->status);
    }

    public function testMarkPendingWorksAfterReconciliation(): void
    {
        $db = $this->sqlite();
        $this->createLegacyTable($db);

        $repo = new JournalRepository($db);
        $repo->markPending(
            module: 'shop',
            patchId: 'backfill-001',
            patchFqcn: 'Shop\\Patch\\Backfill001',
            phase: UpdatePhase::Apply,
            checksum: 'abcd',
            semitexaVersion: '2026.05.11.0000',
        );

        $entry = $repo->findByIdentity('shop', 'backfill-001');
        self::assertNotNull($entry);
        self::assertSame(PatchStatus::Pending, $entry->status);
    }

    public function testReconciledLegacyRowsAreNotRerun(): void
    {
        $db = $this->sqlite();
        $this->createLegacyTable($db);
        $db->query(
            "INSERT INTO " . JournalRepository::TABLE
            . " (id, step_fqcn, phase, checksum, status, started_at, completed_at, duration_ms)"
            . " VALUES ('row-x', 'App\\Old\\Step', 'apply', 'sum', '" . PatchStatus::Applied->value . "',"
            . "         '2026-01-01T00:00:00.000000Z', '2026-01-01T00:00:01.000000Z', 1)"
        );

        $repo = new JournalRepository($db);

        $entries = $repo->findAllByIdentity();
        self::assertSame(
            PatchStatus::Applied,
            $entries['legacy:App\\Old\\Step']->status,
            'Legacy applied rows must keep their applied status after reconciliation so they are not rerun.',
        );
    }

    private function sqlite(): SqliteAdapter
    {
        return new SqliteAdapter('sqlite::memory:');
    }

    private function createLegacyTable(SqliteAdapter $db): void
    {
        $db->query(
            'CREATE TABLE ' . JournalRepository::TABLE . ' ('
            . ' id TEXT PRIMARY KEY,'
            . ' step_fqcn TEXT NOT NULL,'
            . ' phase TEXT NOT NULL,'
            . ' checksum TEXT NOT NULL,'
            . ' status TEXT NOT NULL,'
            . ' started_at TEXT NOT NULL,'
            . ' completed_at TEXT NULL,'
            . ' duration_ms INTEGER NULL,'
            . ' error TEXT NULL,'
            . ' UNIQUE(step_fqcn)'
            . ')'
        );
    }

    /**
     * @return list<string>
     */
    private function columnNames(SqliteAdapter $db): array
    {
        $rows = $db->query('PRAGMA table_info(' . JournalRepository::TABLE . ')')->fetchAll();
        return array_values(array_map(static fn (array $row): string => (string) $row['name'], $rows));
    }
}
