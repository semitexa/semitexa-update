<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldSyncEngine;
use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldFileEntry;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;

final class ScaffoldSyncEngineTest extends TestCase
{
    private string $scaffold;
    private string $project;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/semitexa-engine-' . bin2hex(random_bytes(8));
        $this->scaffold = $base . '/scaffold';
        $this->project = $base . '/project';
        mkdir($this->scaffold . '/bin', 0777, true);
        mkdir($this->project . '/bin', 0777, true);
        $this->now = new \DateTimeImmutable('2026-05-11T15:42:00+00:00');
    }

    protected function tearDown(): void
    {
        $this->rrm(dirname($this->scaffold));
    }

    public function testKnownPreviousIsReplacedAndBackedUp(): void
    {
        $current = "current bin\n";
        $prior   = "prior bin\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $prior);

        $plan = $this->classify($this->binManifest($current, [$prior]));
        $report = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, dryRun: false, now: $this->now);

        $result = $report->resultByPath('bin/semitexa');
        self::assertNotNull($result);
        self::assertSame(ScaffoldSyncOutcome::Applied, $result->outcome);
        self::assertSame(ScaffoldSyncAction::Replace, $result->action);
        self::assertSame($current, file_get_contents($this->project . '/bin/semitexa'));
        self::assertNotNull($result->backupPath);
        $backupAbsolute = $this->project . '/' . $result->backupPath;
        self::assertFileExists($backupAbsolute);
        self::assertSame($prior, file_get_contents($backupAbsolute));
    }

    public function testLocallyModifiedFileWritesNewAndLeavesLiveUntouched(): void
    {
        $current = "current\n";
        $operator = "operator-edited-this\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $operator);

        $plan = $this->classify($this->binManifest($current));
        $report = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);

        self::assertSame($operator, file_get_contents($this->project . '/bin/semitexa'), 'Live file must be untouched.');
        self::assertFileExists($this->project . '/bin/semitexa.new');
        self::assertSame($current, file_get_contents($this->project . '/bin/semitexa.new'));

        $result = $report->resultByPath('bin/semitexa');
        self::assertSame(ScaffoldSyncOutcome::Applied, $result->outcome);
        self::assertSame('bin/semitexa.new', $result->newFilePath);
    }

    public function testDryRunPerformsNoMutation(): void
    {
        $current = "current\n";
        $prior   = "prior\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $prior);

        $beforeSnapshot = $this->fsSnapshot();
        $plan = $this->classify($this->binManifest($current, [$prior]));
        $report = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, dryRun: true, now: $this->now);

        self::assertSame($beforeSnapshot, $this->fsSnapshot(), 'Dry-run must not change the filesystem.');
        self::assertSame(ScaffoldSyncOutcome::WouldApply, $report->resultByPath('bin/semitexa')->outcome);
        self::assertTrue($report->dryRun);
    }

    public function testMissingFileIsCreated(): void
    {
        $this->writeScaffold('bin/semitexa', "current\n");

        $plan = $this->classify($this->binManifest("current\n"));
        (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);

        self::assertFileExists($this->project . '/bin/semitexa');
        self::assertSame("current\n", file_get_contents($this->project . '/bin/semitexa'));
    }

    public function testBinSemitexaReplacementSetsExecutableBit(): void
    {
        $current = "#!/bin/sh\necho ok\n";
        $prior   = "#!/bin/sh\necho old\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $prior, mode: 0644);

        $plan = $this->classify($this->binManifest($current, [$prior]));
        (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);

        $perms = fileperms($this->project . '/bin/semitexa') & 0777;
        self::assertSame(0755, $perms, 'preserve_executable=true must result in +x after Replace.');
    }

    public function testBinSemitexaLocallyModifiedWritesNewInsteadOfOverwrite(): void
    {
        $current = "#!/bin/sh\necho new\n";
        $operator = "#!/bin/sh\n# operator-customized\necho mine\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $operator);

        $plan = $this->classify($this->binManifest($current));
        $report = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);

        self::assertSame($operator, file_get_contents($this->project . '/bin/semitexa'));
        self::assertSame($current, file_get_contents($this->project . '/bin/semitexa.new'));
        self::assertSame(ScaffoldSyncStatus::LocallyModified, $report->resultByPath('bin/semitexa')->status);
    }

    public function testDotEnvIsNeverWrittenByEngine(): void
    {
        $current = "current\n";
        $prior   = "prior\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $prior);
        $this->writeProject('.env', "SECRET=xyz\n");

        $plan = $this->classify($this->binManifest($current, [$prior]));
        (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);

        self::assertSame("SECRET=xyz\n", file_get_contents($this->project . '/.env'),
            '.env must never be touched — the manifest contains .env.default, never .env.');
    }

    public function testEnvDefaultFollowsManifestPolicy(): void
    {
        // Scaffold ships an .env.default; project has an older variant; both are
        // declared in the manifest with auto_update=true → auto-replace with backup.
        $currentEnv = "APP_ENV=prod\n";
        $priorEnv   = "APP_ENV=dev\n";
        $this->writeScaffold('.env.default', $currentEnv);
        $this->writeProject('.env.default', $priorEnv);

        $manifest = $this->manifest([
            new ScaffoldFileEntry(
                path: '.env.default',
                currentSha256: hash('sha256', $currentEnv),
                previousSha256: [hash('sha256', $priorEnv)],
                category: ScaffoldFileCategory::EnvTemplate,
                critical: false,
                autoUpdate: true,
                preserveExecutable: false,
                notes: '',
            ),
        ]);

        $plan = $this->classify($manifest);
        (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);

        self::assertSame($currentEnv, file_get_contents($this->project . '/.env.default'));
    }

    public function testIdempotentOnSecondRun(): void
    {
        $current = "current\n";
        $prior   = "prior\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $prior);

        $manifest = $this->binManifest($current, [$prior]);
        $engine = new ScaffoldSyncEngine();

        $plan1 = $this->classify($manifest);
        $engine->execute($plan1, $this->project, $this->scaffold, now: $this->now);

        $plan2 = $this->classify($manifest);
        $report2 = $engine->execute($plan2, $this->project, $this->scaffold, now: $this->now->modify('+1 hour'));

        $result = $report2->resultByPath('bin/semitexa');
        self::assertSame(ScaffoldSyncOutcome::NoOp, $result->outcome);
        self::assertSame(ScaffoldSyncStatus::Current, $result->status);
    }

    public function testEngineRefusesWhenIntegrityFails(): void
    {
        $this->writeScaffold('bin/semitexa', "scaffold-says-A\n");
        $this->writeProject('bin/semitexa', "scaffold-says-A\n");
        // Manifest claims a different hash than the scaffold actually has.
        $manifest = $this->manifest([
            new ScaffoldFileEntry(
                path: 'bin/semitexa',
                currentSha256: hash('sha256', "B\n"),
                previousSha256: [],
                category: ScaffoldFileCategory::Executable,
                critical: true,
                autoUpdate: true,
                preserveExecutable: true,
                notes: '',
            ),
        ]);

        $plan = $this->classify($manifest);
        self::assertTrue($plan->hasIntegrityFailure());

        $report = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);

        self::assertNotEmpty($report->integrityErrors);
        self::assertSame(ScaffoldSyncOutcome::Skipped, $report->resultByPath('bin/semitexa')->outcome);
        self::assertFalse($report->isSuccess());
        // Live file must not have been touched.
        self::assertSame("scaffold-says-A\n", file_get_contents($this->project . '/bin/semitexa'));
    }

    public function testReportCarriesActionStatusHashesAndPaths(): void
    {
        $current = "current\n";
        $prior   = "prior\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $prior);

        $plan = $this->classify($this->binManifest($current, [$prior]));
        $report = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);
        $r = $report->resultByPath('bin/semitexa');

        self::assertNotNull($r);
        self::assertSame(ScaffoldSyncAction::Replace, $r->action);
        self::assertSame(ScaffoldSyncStatus::KnownPrevious, $r->status);
        self::assertSame(hash('sha256', $prior), $r->oldHash);
        self::assertSame(hash('sha256', $current), $r->newHash);
        self::assertNotNull($r->backupPath);
        self::assertNotEmpty($r->message);
    }

    public function testWriteNewSkipsRewriteWhenExistingNewMatchesCurrent(): void
    {
        $current = "current\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', "operator\n");
        $this->writeProject('bin/semitexa.new', $current); // already exists, matches current

        $plan = $this->classify($this->binManifest($current));
        $report = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);
        $r = $report->resultByPath('bin/semitexa');

        self::assertSame('bin/semitexa.new', $r->newFilePath, 'No timestamp suffix when .new already matches current.');
        self::assertSame($current, file_get_contents($this->project . '/bin/semitexa.new'));
    }

    public function testWriteNewCreatesTimestampVariantWhenNewDiffersFromCurrent(): void
    {
        $current = "current\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', "operator\n");
        $this->writeProject('bin/semitexa.new', "operator-also-tweaked-the-new-file\n");

        $plan = $this->classify($this->binManifest($current));
        $report = (new ScaffoldSyncEngine())->execute($plan, $this->project, $this->scaffold, now: $this->now);
        $r = $report->resultByPath('bin/semitexa');

        self::assertNotNull($r->newFilePath);
        self::assertNotSame('bin/semitexa.new', $r->newFilePath);
        self::assertStringStartsWith('bin/semitexa.new.', $r->newFilePath);
        self::assertFileExists($this->project . '/' . $r->newFilePath);
        // The pre-existing operator-edited .new file must be untouched.
        self::assertSame("operator-also-tweaked-the-new-file\n", file_get_contents($this->project . '/bin/semitexa.new'));
    }

    private function classify(ScaffoldManifest $manifest): \Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlan
    {
        return (new ScaffoldFileClassifier(new ScaffoldHasher()))
            ->plan($this->project, $this->scaffold, $manifest, $this->now);
    }

    /**
     * @param list<string> $previousBytes
     */
    private function binManifest(string $currentBytes, array $previousBytes = []): ScaffoldManifest
    {
        return $this->manifest([
            new ScaffoldFileEntry(
                path: 'bin/semitexa',
                currentSha256: hash('sha256', $currentBytes),
                previousSha256: array_map(static fn (string $b): string => hash('sha256', $b), $previousBytes),
                category: ScaffoldFileCategory::Executable,
                critical: true,
                autoUpdate: true,
                preserveExecutable: true,
                notes: '',
            ),
        ]);
    }

    /**
     * @param list<ScaffoldFileEntry> $entries
     */
    private function manifest(array $entries): ScaffoldManifest
    {
        $byPath = [];
        foreach ($entries as $entry) {
            $byPath[$entry->path] = $entry;
        }
        return new ScaffoldManifest(
            schemaVersion: ScaffoldManifest::SCHEMA_VERSION,
            generatedAt: '2026-05-11T00:00:00+00:00',
            entries: $byPath,
        );
    }

    private function writeScaffold(string $relative, string $bytes): void
    {
        $absolute = $this->scaffold . '/' . $relative;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, $bytes);
    }

    private function writeProject(string $relative, string $bytes, int $mode = 0644): void
    {
        $absolute = $this->project . '/' . $relative;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, $bytes);
        chmod($absolute, $mode);
    }

    /**
     * @return array<string, string>  absolute path → sha256
     */
    private function fsSnapshot(): array
    {
        $hashes = [];
        foreach ([$this->scaffold, $this->project] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($hashes);
        return $hashes;
    }

    private function rrm(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }
}
