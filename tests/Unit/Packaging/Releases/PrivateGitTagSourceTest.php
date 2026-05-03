<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Source\PrivateGitTagSource;

final class PrivateGitTagSourceTest extends TestCase
{
    public function testExtractsLatestStableTagFromLsRemoteOutput(): void
    {
        $source = new PrivateGitTagSource();

        $latest = $source->extractLatestStableTag([
            "1111111111111111111111111111111111111111\trefs/tags/2026.04.03.1200",
            "2222222222222222222222222222222222222222\trefs/tags/2026.04.03.1315",
            "3333333333333333333333333333333333333333\trefs/tags/2026.04.03.1315-beta",
            "4444444444444444444444444444444444444444\trefs/tags/2026.04.03.1315^{}",
        ]);

        self::assertSame('2026.04.03.1315', $latest);
    }

    public function testReturnsNullForEmptyLsRemoteOutput(): void
    {
        $source = new PrivateGitTagSource();

        self::assertNull($source->extractLatestStableTag([]));
    }

    public function testReturnsNullWhenOnlyPrereleaseTagsExist(): void
    {
        $source = new PrivateGitTagSource();

        $latest = $source->extractLatestStableTag([
            "1111111111111111111111111111111111111111\trefs/tags/2026.04.03.1200-beta",
            "2222222222222222222222222222222222222222\trefs/tags/2026.04.03.1315-rc1",
        ]);

        self::assertNull($latest);
    }

    public function testIgnoresMalformedLinesAndStillFindsLatestStableTag(): void
    {
        $source = new PrivateGitTagSource();

        $latest = $source->extractLatestStableTag([
            'malformed line without tab',
            "1111111111111111111111111111111111111111\trefs/heads/main",
            "2222222222222222222222222222222222222222\trefs/tags/2026.04.03.1200",
            "3333333333333333333333333333333333333333\trefs/tags/2026.04.03.1315",
        ]);

        self::assertSame('2026.04.03.1315', $latest);
    }
}
