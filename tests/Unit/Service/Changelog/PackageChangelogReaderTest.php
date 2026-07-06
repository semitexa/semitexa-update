<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Changelog;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Changelog\PackageChangelogReader;

final class PackageChangelogReaderTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-changelog-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/vendor/semitexa/core', 0777, true);
        file_put_contents($this->projectRoot . '/vendor/semitexa/core/CHANGELOG.md', <<<'MD'
# Changelog

## Unreleased

- something brewing

## v2026.07.01.0100 — 2026-07-01

### Added
- new thing

## 2026.06.21.0352

### Fixed
- old bug

## v2026.05.12.0933

- ancient
MD);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/vendor/semitexa/core/CHANGELOG.md');
        @rmdir($this->projectRoot . '/vendor/semitexa/core');
        @rmdir($this->projectRoot . '/vendor/semitexa');
        @rmdir($this->projectRoot . '/vendor');
        @rmdir($this->projectRoot);
    }

    public function testParsesSectionsWithOptionalPrefixAndDate(): void
    {
        $reader = new PackageChangelogReader($this->projectRoot);

        $notes = $reader->allNotes('semitexa/core');

        self::assertCount(4, $notes);
        self::assertSame('Unreleased', $notes[0]->version);
        self::assertSame('2026.07.01.0100', $notes[1]->version);
        self::assertSame('2026-07-01', $notes[1]->date);
        self::assertStringContainsString('new thing', $notes[1]->body);
        self::assertSame('2026.06.21.0352', $notes[2]->version);
        self::assertNull($notes[2]->date);
    }

    public function testNotesBetweenSelectsHalfOpenRangeAndSkipsUnreleased(): void
    {
        $reader = new PackageChangelogReader($this->projectRoot);

        $notes = $reader->notesBetween('semitexa/core', '2026.05.12.0933', '2026.07.01.0100');

        $versions = array_map(static fn ($n) => $n->version, $notes);
        self::assertSame(['2026.07.01.0100', '2026.06.21.0352'], $versions);
    }

    public function testNullFromIncludesEverythingUpToTarget(): void
    {
        $reader = new PackageChangelogReader($this->projectRoot);

        $notes = $reader->notesBetween('semitexa/core', null, '2026.06.21.0352');

        $versions = array_map(static fn ($n) => $n->version, $notes);
        self::assertSame(['2026.06.21.0352', '2026.05.12.0933'], $versions);
    }

    public function testMissingChangelogYieldsEmpty(): void
    {
        $reader = new PackageChangelogReader($this->projectRoot);

        self::assertSame([], $reader->allNotes('semitexa/nonexistent'));
        self::assertSame([], $reader->notesBetween('acme/other', null, '1.0.0'));
    }
}
