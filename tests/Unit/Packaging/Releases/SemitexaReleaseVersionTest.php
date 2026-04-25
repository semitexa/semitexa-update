<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Packaging\Releases\Support\SemitexaReleaseVersion;

final class SemitexaReleaseVersionTest extends TestCase
{
    public function testRecognizesStableVersion(): void
    {
        self::assertTrue(SemitexaReleaseVersion::isValid('2026.04.03.1315'));
        self::assertTrue(SemitexaReleaseVersion::isStable('2026.04.03.1315'));
        self::assertFalse(SemitexaReleaseVersion::isStable('2026.04.03.1315-beta'));
        self::assertTrue(SemitexaReleaseVersion::isValid('1.0.12'));
        self::assertTrue(SemitexaReleaseVersion::isStable('1.0.12'));
        self::assertFalse(SemitexaReleaseVersion::isStable('1.0.12-rc1'));
    }

    public function testComparesChronologicalVersions(): void
    {
        self::assertGreaterThan(
            0,
            SemitexaReleaseVersion::compare('2026.04.03.1315', '2026.04.02.2359'),
        );
    }

    public function testComparesSemanticVersions(): void
    {
        self::assertGreaterThan(0, SemitexaReleaseVersion::compare('1.1.62', '1.1.58'));
        self::assertGreaterThan(0, SemitexaReleaseVersion::compare('0.1.4', '0.1.3'));
        self::assertGreaterThan(0, SemitexaReleaseVersion::compare('1.0.12', '1.0.12-beta'));
    }

    public function testPrefersSemanticVersionsWhenSchemesAreMixed(): void
    {
        self::assertGreaterThan(0, SemitexaReleaseVersion::compare('1.2.3', '2024.01.15.0001'));
        self::assertLessThan(0, SemitexaReleaseVersion::compare('2024.01.15.0001', '1.2.3'));
    }
}
