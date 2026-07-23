<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Composer;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Composer\P2MetadataExpander;
use Semitexa\Update\Application\Service\Composer\SkeletonRequireDiff;

final class SkeletonRequireDiffTest extends TestCase
{
    private string $projectRoot = '';

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/skeleton-require-diff-' . uniqid();
        mkdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/composer.json');
        @rmdir($this->projectRoot);
    }

    #[Test]
    public function proposesSkeletonPackagesTheProjectNeverDeclared(): void
    {
        $this->writeComposerJson(['semitexa/core' => '2026.07.01.0000']);

        $diff = $this->diff([
            'semitexa/ultimate' => [[
                'version' => '2026.07.22.1910',
                'require' => [
                    'php' => '^8.4',
                    'semitexa/core' => '2026.07.22.1910',
                    'semitexa/media' => '2026.07.22.1910',
                ],
            ]],
            'semitexa/media' => [[
                'version' => '2026.07.22.1910',
                'description' => 'Image-first media asset management',
            ]],
        ]);

        $missing = $diff->missingPackages($this->projectRoot);

        self::assertNotNull($missing);
        self::assertCount(1, $missing);
        self::assertSame('semitexa/media', $missing[0]->name);
        self::assertSame('2026.07.22.1910', $missing[0]->pinnedVersion);
        self::assertSame('Image-first media asset management', $missing[0]->description);
    }

    #[Test]
    public function requireDevDeclarationsCountAsDeclared(): void
    {
        $this->writeComposerJson([], ['semitexa/testing' => '*']);

        $diff = $this->diff([
            'semitexa/ultimate' => [[
                'version' => '2026.07.22.1910',
                'require' => ['semitexa/testing' => '2026.07.22.1910'],
            ]],
        ]);

        self::assertSame([], $diff->missingPackages($this->projectRoot));
    }

    #[Test]
    public function usesTheLatestStableSkeletonReleaseAfterP2Expansion(): void
    {
        $this->writeComposerJson([]);

        // p2-minified: newest row first and complete, older row is a diff.
        $diff = $this->diff([
            'semitexa/ultimate' => [
                [
                    'version' => '2026.07.22.1910',
                    'require' => ['semitexa/media' => '2026.07.22.1910'],
                ],
                [
                    'version' => '2026.07.20.0639',
                    'require' => '__unset',
                ],
            ],
            'semitexa/media' => [[
                'version' => '2026.07.22.1910',
                'description' => 'Media',
            ]],
        ]);

        $missing = $diff->missingPackages($this->projectRoot);

        self::assertNotNull($missing);
        self::assertSame(['semitexa/media'], array_column($missing, 'name'));
        self::assertSame('2026.07.22.1910', $missing[0]->pinnedVersion);
    }

    #[Test]
    public function unreachableUpstreamYieldsNullNotFailure(): void
    {
        $this->writeComposerJson([]);

        $diff = $this->diff([]);

        self::assertNull($diff->missingPackages($this->projectRoot));
    }

    #[Test]
    public function unparsableLocalComposerJsonYieldsNoAdvisory(): void
    {
        file_put_contents($this->projectRoot . '/composer.json', '{not-json');

        $diff = $this->diff([
            'semitexa/ultimate' => [[
                'version' => '2026.07.22.1910',
                'require' => ['semitexa/media' => '2026.07.22.1910'],
            ]],
        ]);

        self::assertNull($diff->missingPackages($this->projectRoot), 'a broken composer.json must not propose everything');
    }

    #[Test]
    public function missingLocalComposerJsonYieldsNoAdvisory(): void
    {
        $diff = $this->diff([
            'semitexa/ultimate' => [[
                'version' => '2026.07.22.1910',
                'require' => ['semitexa/media' => '2026.07.22.1910'],
            ]],
        ]);

        self::assertNull($diff->missingPackages($this->projectRoot));
    }

    #[Test]
    public function p2ExpanderInheritsAndUnsetsFields(): void
    {
        $expanded = P2MetadataExpander::expand([
            ['version' => '2', 'require' => ['a' => '1'], 'description' => 'x'],
            ['version' => '1', 'description' => '__unset'],
        ]);

        self::assertSame(['a' => '1'], $expanded[1]['require']);
        self::assertArrayNotHasKey('description', $expanded[1]);
        self::assertSame('x', $expanded[0]['description']);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $rowsByPackage
     */
    private function diff(array $rowsByPackage): SkeletonRequireDiff
    {
        return new SkeletonRequireDiff(
            fetchRows: static fn (string $package): ?array => $rowsByPackage[$package] ?? null,
        );
    }

    /**
     * @param array<string, string> $require
     * @param array<string, string> $requireDev
     */
    private function writeComposerJson(array $require, array $requireDev = []): void
    {
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'consumer/app',
            'require' => (object) $require,
            'require-dev' => (object) $requireDev,
        ], JSON_THROW_ON_ERROR));
    }
}
