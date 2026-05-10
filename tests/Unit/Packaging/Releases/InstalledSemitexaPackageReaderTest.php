<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\InstalledSemitexaPackageReader;

final class InstalledSemitexaPackageReaderTest extends TestCase
{
    public function testReadsSemanticAndDateBasedSemitexaVersionsFromComposerLock(): void
    {
        $projectRoot = $this->writeLock([
            'packages' => [
                ['name' => 'semitexa/core', 'version' => '1.1.62'],
                ['name' => 'semitexa/dev', 'version' => '2026.04.03.1207'],
                ['name' => 'psr/container', 'version' => '2.0.2'],
            ],
            'packages-dev' => [
                ['name' => 'semitexa/testing', 'version' => '1.0.10'],
            ],
        ]);

        $packages = (new InstalledSemitexaPackageReader())->read($projectRoot);

        self::assertSame([
            'semitexa/core' => '1.1.62',
            'semitexa/dev' => '2026.04.03.1207',
            'semitexa/testing' => '1.0.10',
        ], $packages);
    }

    public function testReadOmitsPathRepositoryEntries(): void
    {
        $projectRoot = $this->writeLock([
            'packages' => [
                [
                    'name' => 'semitexa/core',
                    'version' => '1.1.62',
                ],
                [
                    'name' => 'semitexa/site',
                    'version' => 'dev-main',
                    'dist' => ['type' => 'path', 'url' => 'packages/semitexa-site'],
                ],
            ],
        ]);

        self::assertSame(
            ['semitexa/core' => '1.1.62'],
            (new InstalledSemitexaPackageReader())->read($projectRoot),
        );
    }

    public function testClassifyPartitionsVendorAndLocalWorkspacePackages(): void
    {
        $projectRoot = $this->writeLock([
            'packages' => [
                [
                    'name' => 'semitexa/core',
                    'version' => '1.1.62',
                ],
                [
                    'name' => 'semitexa/site',
                    'version' => 'dev-main',
                    'dist' => ['type' => 'path', 'url' => 'packages/semitexa-site'],
                ],
                [
                    'name' => 'semitexa/update',
                    'version' => 'dev-feature',
                    'source' => ['type' => 'path', 'url' => 'packages/semitexa-update'],
                ],
                [
                    'name' => 'semitexa/orm',
                    'version' => '2026.05.01.0900',
                ],
                [
                    'name' => 'psr/container',
                    'version' => '2.0.2',
                ],
            ],
        ]);

        $classified = (new InstalledSemitexaPackageReader())->classify($projectRoot);

        self::assertSame(['semitexa/core', 'semitexa/orm'], $classified->vendorNames());
        self::assertSame(['semitexa/site', 'semitexa/update'], $classified->localWorkspaceNames());

        self::assertSame('1.1.62', $classified->vendor['semitexa/core']);
        self::assertSame('packages/semitexa-site', $classified->localWorkspace['semitexa/site']->sourceUrl);
        self::assertSame('packages/semitexa-update', $classified->localWorkspace['semitexa/update']->sourceUrl);
        self::assertSame('dev-main', $classified->localWorkspace['semitexa/site']->version);
    }

    public function testClassifyHandlesMissingLockFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-empty-' . bin2hex(random_bytes(4));
        mkdir($projectRoot, 0777, true);

        $classified = (new InstalledSemitexaPackageReader())->classify($projectRoot);

        self::assertFalse($classified->hasVendor());
        self::assertFalse($classified->hasLocalWorkspace());
    }

    /**
     * @param array<string, mixed> $lockBody
     */
    private function writeLock(array $lockBody): string
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-reader-' . bin2hex(random_bytes(4));
        mkdir($projectRoot, 0777, true);

        file_put_contents(
            $projectRoot . '/composer.lock',
            json_encode($lockBody, JSON_THROW_ON_ERROR),
        );

        return $projectRoot;
    }
}
