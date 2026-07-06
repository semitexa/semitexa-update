<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Domain\Enum\RunOutcome;

final class RunJournalRepositoryTest extends TestCase
{
    public function testBeginInsertsRunningRow(): void
    {
        $repo = new RunJournalRepository($this->sqlite());

        $id = $repo->begin(RunJournalRepository::KIND_UPDATE, 'cli:tester', '2026.07.06.0001');

        $record = $repo->find($id);
        self::assertNotNull($record);
        self::assertSame(RunOutcome::Running, $record->outcome);
        self::assertSame('update', $record->kind);
        self::assertSame('cli:tester', $record->actor);
        self::assertSame('2026.07.06.0001', $record->updaterVersion);
        self::assertNull($record->completedAt);
        self::assertNull($record->durationMs);
    }

    public function testFinishRecordsOutcomeStagesDeltasAndDuration(): void
    {
        $repo = new RunJournalRepository($this->sqlite());
        $id = $repo->begin(RunJournalRepository::KIND_UPDATE, 'cli:tester', null);

        $stages = [
            ['name' => 'composer-update', 'success' => true, 'bumped_packages' => 2],
            ['name' => 'apply-patches', 'success' => true, 'applied_patches' => 1],
        ];
        $deltas = [
            'semitexa/core' => ['from' => '2026.06.21.0352', 'to' => '2026.07.06.0001'],
        ];

        $repo->finish($id, RunOutcome::Success, null, $stages, $deltas, 1, null);

        $record = $repo->find($id);
        self::assertNotNull($record);
        self::assertSame(RunOutcome::Success, $record->outcome);
        self::assertNull($record->failedStage);
        self::assertSame($stages, $record->stages);
        self::assertSame($deltas, $record->packageDeltas);
        self::assertSame(1, $record->patchesApplied);
        self::assertNotNull($record->completedAt);
        self::assertNotNull($record->durationMs);
        self::assertGreaterThanOrEqual(0, $record->durationMs);
    }

    public function testFinishRecordsFailure(): void
    {
        $repo = new RunJournalRepository($this->sqlite());
        $id = $repo->begin(RunJournalRepository::KIND_AUTO_DEPLOY, 'auto-deploy', null);

        $repo->finish($id, RunOutcome::Failed, 'orm-sync', [], [], 0, 'Command failed: orm:sync');

        $record = $repo->find($id);
        self::assertNotNull($record);
        self::assertSame(RunOutcome::Failed, $record->outcome);
        self::assertSame('orm-sync', $record->failedStage);
        self::assertSame('Command failed: orm:sync', $record->error);
    }

    public function testFindRecentReturnsNewestFirstAndHonorsLimit(): void
    {
        $repo = new RunJournalRepository($this->sqlite());

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $repo->begin(RunJournalRepository::KIND_UPDATE, 'cli:tester', null);
            usleep(1500);
        }

        $recent = $repo->findRecent(2);

        self::assertCount(2, $recent);
        self::assertSame($ids[2], $recent[0]->id);
        self::assertSame($ids[1], $recent[1]->id);
    }

    public function testFindAcceptsUniquePrefixButNotAmbiguousOne(): void
    {
        $repo = new RunJournalRepository($this->sqlite());
        $id = $repo->begin(RunJournalRepository::KIND_UPDATE, 'cli:tester', null);

        self::assertNotNull($repo->find(substr($id, 0, 12)));
        self::assertNotNull($repo->find($id));
        self::assertNull($repo->find(''), 'Empty prefix must not match everything.');
    }

    private function sqlite(): SqliteAdapter
    {
        return new SqliteAdapter('sqlite::memory:');
    }
}
