<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\PackageDriftInspector;
use Semitexa\Update\Domain\Enum\PackageDriftStatus;

final class PackageDriftInspectorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/semitexa-drift-' . bin2hex(random_bytes(8));
        mkdir($this->tmp . '/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmp);
    }

    public function testEverythingAlignedIsClean(): void
    {
        $this->writeProject(
            declared: ['semitexa/core' => '2026.05.11.1359', 'semitexa/orm' => '2026.05.11.1359'],
            locked:   ['semitexa/core' => '2026.05.11.1359', 'semitexa/orm' => '2026.05.11.1359'],
            installed:['semitexa/core' => '2026.05.11.1359', 'semitexa/orm' => '2026.05.11.1359'],
        );

        $report = (new PackageDriftInspector())->inspect($this->tmp);

        self::assertCount(2, $report->entries);
        foreach ($report->entries as $entry) {
            self::assertSame(PackageDriftStatus::Clean, $entry->status, $entry->name);
        }
        self::assertFalse($report->hasActionableDrift());
        self::assertTrue($report->releaseSetCoherent);
    }

    public function testLockStaleWhenComposerJsonPinDiffersFromLock(): void
    {
        $this->writeProject(
            declared: ['semitexa/core' => '2026.05.11.1359'],
            locked:   ['semitexa/core' => '2026.05.08.1640'],
            installed:['semitexa/core' => '2026.05.08.1640'],
        );

        $entry = (new PackageDriftInspector())->inspect($this->tmp)->entryByName('semitexa/core');

        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::LockStale, $entry->status);
        self::assertStringContainsString('composer update semitexa/core', $entry->actionHint);
    }

    public function testVendorStaleWhenLockDiffersFromInstalled(): void
    {
        $this->writeProject(
            declared: ['semitexa/core' => '2026.05.11.1359'],
            locked:   ['semitexa/core' => '2026.05.11.1359'],
            installed:['semitexa/core' => '2026.05.08.1640'],
        );

        $entry = (new PackageDriftInspector())->inspect($this->tmp)->entryByName('semitexa/core');

        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::VendorStale, $entry->status);
        self::assertStringContainsString('composer install', $entry->actionHint);
    }

    public function testMissingFromVendor(): void
    {
        $this->writeProject(
            declared: ['semitexa/core' => '2026.05.11.1359'],
            locked:   ['semitexa/core' => '2026.05.11.1359'],
            installed:[],
        );

        $entry = (new PackageDriftInspector())->inspect($this->tmp)->entryByName('semitexa/core');

        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::MissingFromVendor, $entry->status);
    }

    public function testMissingFromLock(): void
    {
        $this->writeProject(
            declared: ['semitexa/core' => '2026.05.11.1359'],
            locked:   [],
            installed:[],
        );

        $entry = (new PackageDriftInspector())->inspect($this->tmp)->entryByName('semitexa/core');

        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::MissingFromLock, $entry->status);
    }

    public function testDevConstraintIsNotDrift(): void
    {
        $this->writeProject(
            declared: ['semitexa/platform-ui' => '@dev'],
            locked:   ['semitexa/platform-ui' => 'dev-develop'],
            installed:['semitexa/platform-ui' => 'dev-develop'],
        );

        $entry = (new PackageDriftInspector())->inspect($this->tmp)->entryByName('semitexa/platform-ui');

        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::DevConstraint, $entry->status);
        self::assertFalse($entry->status->isActionable());
    }

    public function testPathRepositoryIsNotDrift(): void
    {
        $this->writeProject(
            declared: ['semitexa/update' => '*'],
            lockedPackages: [
                ['name' => 'semitexa/update', 'version' => '2026.05.10.1449',
                 'dist' => ['type' => 'path', 'url' => 'packages/semitexa-update']],
            ],
            installedPackages: [
                ['name' => 'semitexa/update', 'version' => '2026.05.10.1449',
                 'dist' => ['type' => 'path', 'url' => '../../packages/semitexa-update']],
            ],
        );

        $entry = (new PackageDriftInspector())->inspect($this->tmp)->entryByName('semitexa/update');

        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::PathRepository, $entry->status);
        self::assertFalse($entry->status->isActionable());
    }

    public function testWildcardConstraintWithMatchingLockAndVendorIsClean(): void
    {
        $this->writeProject(
            declared: ['semitexa/core' => '*'],
            locked:   ['semitexa/core' => '2026.05.11.1359'],
            installed:['semitexa/core' => '2026.05.11.1359'],
        );

        $entry = (new PackageDriftInspector())->inspect($this->tmp)->entryByName('semitexa/core');

        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::Clean, $entry->status);
    }

    public function testWildcardConstraintStillFlagsVendorStale(): void
    {
        $this->writeProject(
            declared: ['semitexa/core' => '*'],
            locked:   ['semitexa/core' => '2026.05.11.1359'],
            installed:['semitexa/core' => '2026.05.08.1640'],
        );

        $entry = (new PackageDriftInspector())->inspect($this->tmp)->entryByName('semitexa/core');

        self::assertNotNull($entry);
        self::assertSame(PackageDriftStatus::VendorStale, $entry->status);
    }

    public function testMixedReleaseSetFlagsEveryAlignedPackage(): void
    {
        $this->writeProject(
            declared: [
                'semitexa/core' => '2026.05.11.1359',
                'semitexa/orm'  => '2026.05.08.1640',
            ],
            locked: [
                'semitexa/core' => '2026.05.11.1359',
                'semitexa/orm'  => '2026.05.08.1640',
            ],
            installed: [
                'semitexa/core' => '2026.05.11.1359',
                'semitexa/orm'  => '2026.05.08.1640',
            ],
        );

        $report = (new PackageDriftInspector())->inspect($this->tmp);

        self::assertFalse($report->releaseSetCoherent);
        self::assertSame(['2026.05.08', '2026.05.11'], $report->mixedReleaseDates);
        foreach ($report->entries as $entry) {
            self::assertSame(PackageDriftStatus::MixedReleaseSet, $entry->status, $entry->name);
            self::assertStringContainsString('multiple release dates', $entry->actionHint);
        }
    }

    public function testNonSemitexaPackagesAreIgnored(): void
    {
        $this->writeProject(
            declared: [
                'semitexa/core'    => '2026.05.11.1359',
                'symfony/console'  => '^7.0',
                'league/commonmark'=> '^2.8',
            ],
            locked: [
                'semitexa/core'    => '2026.05.11.1359',
                'symfony/console'  => '7.1.0',
                'league/commonmark'=> '2.8.1',
            ],
            installed: [
                'semitexa/core'    => '2026.05.11.1359',
                'symfony/console'  => '6.4.0',
                'league/commonmark'=> '2.8.0',
            ],
        );

        $report = (new PackageDriftInspector())->inspect($this->tmp);

        self::assertCount(1, $report->entries);
        self::assertSame('semitexa/core', $report->entries[0]->name);
        self::assertSame(PackageDriftStatus::Clean, $report->entries[0]->status);
    }

    public function testMissingComposerFilesProduceEmptyReport(): void
    {
        $report = (new PackageDriftInspector())->inspect($this->tmp);
        self::assertSame([], $report->entries);
        self::assertTrue($report->releaseSetCoherent);
        self::assertFalse($report->hasActionableDrift());
    }

    public function testInspectorPerformsNoMutationOnProjectFiles(): void
    {
        $this->writeProject(
            declared: ['semitexa/core' => '2026.05.11.1359'],
            locked:   ['semitexa/core' => '2026.05.08.1640'],
            installed:['semitexa/core' => '2026.05.08.1640'],
        );

        $snapshot = $this->snapshot();
        (new PackageDriftInspector())->inspect($this->tmp);

        self::assertSame($snapshot, $this->snapshot(), 'Inspector must be read-only.');
    }

    /**
     * @param array<string, string>             $declared
     * @param array<string, string>|null        $locked
     * @param array<string, string>|null        $installed
     * @param list<array<string, mixed>>|null   $lockedPackages    raw lock entries (overrides $locked)
     * @param list<array<string, mixed>>|null   $installedPackages raw install entries (overrides $installed)
     */
    private function writeProject(
        array $declared,
        ?array $locked = null,
        ?array $installed = null,
        ?array $lockedPackages = null,
        ?array $installedPackages = null,
    ): void {
        file_put_contents(
            $this->tmp . '/composer.json',
            json_encode(['require' => $declared], JSON_PRETTY_PRINT),
        );

        if ($lockedPackages === null && $locked !== null) {
            $lockedPackages = [];
            foreach ($locked as $name => $version) {
                $lockedPackages[] = ['name' => $name, 'version' => $version];
            }
        }
        if ($lockedPackages !== null) {
            file_put_contents(
                $this->tmp . '/composer.lock',
                json_encode(['packages' => $lockedPackages, 'packages-dev' => []], JSON_PRETTY_PRINT),
            );
        }

        if ($installedPackages === null && $installed !== null) {
            $installedPackages = [];
            foreach ($installed as $name => $version) {
                $installedPackages[] = ['name' => $name, 'version' => $version];
            }
        }
        if ($installedPackages !== null) {
            file_put_contents(
                $this->tmp . '/vendor/composer/installed.json',
                json_encode(['packages' => $installedPackages], JSON_PRETTY_PRINT),
            );
        }
    }

    /**
     * @return array<string, string>  path => sha256
     */
    private function snapshot(): array
    {
        $hashes = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
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
        foreach ($iter as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }
}
