<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteDeployEnvBuilder;

final class RemoteDeployEnvBuilderTest extends TestCase
{
    public function testBuildsGeneratedProductionDefaults(): void
    {
        $path = (new RemoteDeployEnvBuilder())->build(null, 'example.com');

        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertStringContainsString('APP_ENV=prod', $content);
        self::assertStringContainsString('APP_DEBUG=0', $content);
        self::assertMatchesRegularExpression('/^APP_SECRET=[a-f0-9]{64}$/m', $content);
        self::assertStringContainsString('SEMITEXA_REMOTE_DEPLOY_DOMAIN=example.com', $content);

        @unlink($path);
    }

    public function testCopiesProvidedRemoteEnvFile(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'semitexa-remote-env-source-');
        self::assertNotFalse($source);
        file_put_contents($source, "APP_ENV=prod\nCUSTOM_FLAG=1\n");

        $path = (new RemoteDeployEnvBuilder())->build($source, null);

        self::assertFileExists($path);
        self::assertSame("APP_ENV=prod\nCUSTOM_FLAG=1\n", file_get_contents($path));

        @unlink($source);
        @unlink($path);
    }
}
