<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Packaging\RemoteBootstrap\Data\RemoteOsInfo;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteScenarioResolver;

final class RemoteScenarioResolverTest extends TestCase
{
    public function testResolvesUbuntuScenarioDirectory(): void
    {
        $resolver = new RemoteScenarioResolver();
        $packageRoot = dirname(__DIR__, 4);
        $path = $resolver->resolve(
            new RemoteOsInfo('ubuntu', '22.04', 'Ubuntu 22.04 LTS'),
            $packageRoot,
        );

        self::assertStringEndsWith('/resources/remote-deploy/ubuntu/22.04', $path);
    }

    public function testRejectsUnsupportedUbuntuVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        (new RemoteScenarioResolver())->normalizeUbuntuVersion('18.04');
    }
}
