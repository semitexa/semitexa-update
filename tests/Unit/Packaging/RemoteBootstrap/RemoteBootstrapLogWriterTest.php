<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteBootstrapLogWriter;

final class RemoteBootstrapLogWriterTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-remote-log-test-' . uniqid('', true);
        mkdir($this->projectRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRoot));
    }

    public function testWritesStructuredRemoteBootstrapLog(): void
    {
        $path = (new RemoteBootstrapLogWriter())->write($this->projectRoot, [
            'status' => 'ready',
            'target' => 'deploy@example.com',
        ]);

        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertStringContainsString('"status": "ready"', $content);
        self::assertStringContainsString('"target": "deploy@example.com"', $content);
    }
}
