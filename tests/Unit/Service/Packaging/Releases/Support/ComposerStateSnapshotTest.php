<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Packaging\Releases\Support;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\ComposerStateSnapshot;

final class ComposerStateSnapshotTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-snapshot-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot . '/composer.json');
        @unlink($this->projectRoot . '/composer.lock');
        @rmdir($this->projectRoot);
    }

    public function testCaptureAndRestoreRoundtrip(): void
    {
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{"semitexa/core":"1.0.0"}}');
        file_put_contents($this->projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"1.0.0"}]}');

        $snapshot = ComposerStateSnapshot::capture($this->projectRoot);
        self::assertNotNull($snapshot);

        // Simulate what a composer update does before the deploy fails.
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{"semitexa/core":"2.0.0"}}');
        file_put_contents($this->projectRoot . '/composer.lock', '{"packages":[{"name":"semitexa/core","version":"2.0.0"}]}');

        self::assertTrue($snapshot->restoreFiles());
        self::assertStringContainsString('1.0.0', (string) file_get_contents($this->projectRoot . '/composer.json'));
        self::assertStringContainsString('1.0.0', (string) file_get_contents($this->projectRoot . '/composer.lock'));
    }

    public function testCaptureWithoutLockRemovesLockCreatedByFailedUpdate(): void
    {
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{}}');

        $snapshot = ComposerStateSnapshot::capture($this->projectRoot);
        self::assertNotNull($snapshot);

        // The failed update mutated composer.json AND created a lock file.
        file_put_contents($this->projectRoot . '/composer.json', '{"require":{"x/y":"1"}}');
        file_put_contents($this->projectRoot . '/composer.lock', '{"packages":[{"name":"x/y","version":"1"}]}');

        self::assertTrue($snapshot->restoreFiles());
        self::assertSame('{"require":{}}', file_get_contents($this->projectRoot . '/composer.json'));
        self::assertFileDoesNotExist(
            $this->projectRoot . '/composer.lock',
            'A lock created by the failed update must not survive the rollback.',
        );
    }

    public function testCaptureReturnsNullWithoutComposerJson(): void
    {
        self::assertNull(ComposerStateSnapshot::capture($this->projectRoot));
    }
}
