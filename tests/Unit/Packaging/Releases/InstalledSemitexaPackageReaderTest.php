<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\InstalledSemitexaPackageReader;

final class InstalledSemitexaPackageReaderTest extends TestCase
{
    public function testReadsSemanticAndDateBasedSemitexaVersionsFromComposerLock(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-dev-reader-' . bin2hex(random_bytes(4));
        mkdir($projectRoot, 0777, true);

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'semitexa/core', 'version' => '1.1.62'],
                ['name' => 'semitexa/dev', 'version' => '2026.04.03.1207'],
                ['name' => 'psr/container', 'version' => '2.0.2'],
            ],
            'packages-dev' => [
                ['name' => 'semitexa/testing', 'version' => '1.0.10'],
            ],
        ], JSON_THROW_ON_ERROR));

        $packages = (new InstalledSemitexaPackageReader())->read($projectRoot);

        self::assertSame([
            'semitexa/core' => '1.1.62',
            'semitexa/dev' => '2026.04.03.1207',
            'semitexa/testing' => '1.0.10',
        ], $packages);
    }
}
