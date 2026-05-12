<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldFileClassifier;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldFileEntry;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;

final class ScaffoldFileClassifierTest extends TestCase
{
    private string $scaffold;
    private string $project;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/semitexa-classifier-' . bin2hex(random_bytes(8));
        $this->scaffold = $base . '/scaffold';
        $this->project = $base . '/project';
        mkdir($this->scaffold . '/bin', 0777, true);
        mkdir($this->project, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrm(dirname($this->scaffold));
    }

    public function testCurrentFileIsClassifiedAsCurrentWithNoAction(): void
    {
        $scaffoldContents = "#!/bin/sh\nexec /usr/bin/semitexa \"\$@\"\n";
        $this->writeScaffold('bin/semitexa', $scaffoldContents);
        $this->writeProject('bin/semitexa', $scaffoldContents);

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $this->binManifest($scaffoldContents));

        $entry = $plan->entryByPath('bin/semitexa');
        self::assertNotNull($entry);
        self::assertSame(ScaffoldSyncStatus::Current, $entry->status);
        self::assertSame(ScaffoldSyncAction::None, $entry->action);
        self::assertSame([], $plan->integrityErrors);
    }

    public function testKnownPreviousIsClassifiedForReplace(): void
    {
        $current = "current\n";
        $prior   = "prior\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $prior);

        $manifest = $this->manifest([$this->binEntry(
            currentBytes: $current,
            previousBytes: [$prior],
            autoUpdate: true,
        )]);

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $manifest);
        $entry = $plan->entryByPath('bin/semitexa');

        self::assertNotNull($entry);
        self::assertSame(ScaffoldSyncStatus::KnownPrevious, $entry->status);
        self::assertSame(ScaffoldSyncAction::Replace, $entry->action);
        self::assertNotNull($entry->backupPath);
        self::assertStringStartsWith('bin/semitexa.bak.', $entry->backupPath);
    }

    public function testKnownPreviousWithAutoUpdateFalseProducesWriteNew(): void
    {
        $current = "current\n";
        $prior   = "prior\n";
        $this->writeScaffold('bin/semitexa', $current);
        $this->writeProject('bin/semitexa', $prior);

        $manifest = $this->manifest([$this->binEntry(
            currentBytes: $current,
            previousBytes: [$prior],
            autoUpdate: false,
        )]);

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $manifest);
        $entry = $plan->entryByPath('bin/semitexa');

        self::assertNotNull($entry);
        self::assertSame(ScaffoldSyncStatus::KnownPrevious, $entry->status);
        self::assertSame(ScaffoldSyncAction::WriteNew, $entry->action);
        self::assertSame('bin/semitexa.new', $entry->newFilePath);
    }

    public function testLocallyModifiedIsClassifiedAsConflict(): void
    {
        $this->writeScaffold('bin/semitexa', "current\n");
        $this->writeProject('bin/semitexa', "operator-edited\n");

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $this->binManifest());
        $entry = $plan->entryByPath('bin/semitexa');

        self::assertNotNull($entry);
        self::assertSame(ScaffoldSyncStatus::LocallyModified, $entry->status);
        self::assertSame(ScaffoldSyncAction::WriteNew, $entry->action);
        self::assertSame('bin/semitexa.new', $entry->newFilePath);
    }

    public function testMissingWithAutoUpdateTrueIsClassifiedAsCreate(): void
    {
        $this->writeScaffold('bin/semitexa', "current\n");

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $this->binManifest());
        $entry = $plan->entryByPath('bin/semitexa');

        self::assertNotNull($entry);
        self::assertSame(ScaffoldSyncStatus::Missing, $entry->status);
        self::assertSame(ScaffoldSyncAction::Create, $entry->action);
        self::assertNull($entry->oldHash);
    }

    public function testMissingWithAutoUpdateFalseIsClassifiedAsWriteNew(): void
    {
        $this->writeScaffold('bin/semitexa', "current\n");
        $manifest = $this->manifest([$this->binEntry(currentBytes: "current\n", autoUpdate: false)]);

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $manifest);
        $entry = $plan->entryByPath('bin/semitexa');

        self::assertNotNull($entry);
        self::assertSame(ScaffoldSyncStatus::Missing, $entry->status);
        self::assertSame(ScaffoldSyncAction::WriteNew, $entry->action);
    }

    public function testIntegrityFailureWhenScaffoldFileMissing(): void
    {
        $manifest = $this->binManifest("current\n");
        // scaffold dir has no bin/semitexa
        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $manifest);

        self::assertTrue($plan->hasIntegrityFailure());
        self::assertCount(1, $plan->integrityErrors);
        self::assertStringContainsString('Scaffold source missing', $plan->integrityErrors[0]);
    }

    public function testIntegrityFailureWhenScaffoldHashDiffersFromManifest(): void
    {
        $this->writeScaffold('bin/semitexa', "scaffold-says-A\n");
        $manifest = $this->manifest([$this->binEntry(
            // Manifest claims the scaffold currentSha256 is the hash of "B"
            // but the scaffold dir actually has "scaffold-says-A".
            currentBytesForHash: "B\n",
            autoUpdate: true,
        )]);

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $manifest);

        self::assertTrue($plan->hasIntegrityFailure());
        self::assertStringContainsString('manifest current_sha256 is', $plan->integrityErrors[0]);
    }

    public function testReasonStringExplainsClassification(): void
    {
        $this->writeScaffold('bin/semitexa', "current\n");
        $this->writeProject('bin/semitexa', "operator\n");

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $this->binManifest());
        self::assertStringContainsString('locally modified', $plan->entryByPath('bin/semitexa')->reason);
    }

    public function testActionableFiltersOutCurrent(): void
    {
        $contents = "same\n";
        $this->writeScaffold('bin/semitexa', $contents);
        $this->writeProject('bin/semitexa', $contents);

        $plan = (new ScaffoldFileClassifier())->plan($this->project, $this->scaffold, $this->binManifest($contents));
        self::assertSame([], $plan->actionable());
        self::assertSame([], $plan->conflicts());
    }

    private function binManifest(string $scaffoldBytes = "current\n"): ScaffoldManifest
    {
        return $this->manifest([$this->binEntry(currentBytes: $scaffoldBytes, autoUpdate: true)]);
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

    /**
     * @param list<string> $previousBytes
     */
    private function binEntry(
        string $currentBytes = "current\n",
        array $previousBytes = [],
        bool $autoUpdate = true,
        ?string $currentBytesForHash = null,
    ): ScaffoldFileEntry {
        $hasher = new ScaffoldHasher();
        $current = $hasher->hashBytes($currentBytesForHash ?? $currentBytes);
        $previous = array_map(static fn (string $b): string => hash('sha256', $b), $previousBytes);

        return new ScaffoldFileEntry(
            path: 'bin/semitexa',
            currentSha256: $current,
            previousSha256: $previous,
            category: ScaffoldFileCategory::Executable,
            critical: true,
            autoUpdate: $autoUpdate,
            preserveExecutable: true,
            notes: '',
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

    private function writeProject(string $relative, string $bytes): void
    {
        $absolute = $this->project . '/' . $relative;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }
        file_put_contents($absolute, $bytes);
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
