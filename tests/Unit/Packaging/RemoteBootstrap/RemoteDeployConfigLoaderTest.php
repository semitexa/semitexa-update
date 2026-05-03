<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support\RemoteDeployConfigLoader;

final class RemoteDeployConfigLoaderTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'SEMITEXA_REMOTE_DEPLOY_TARGETS',
            'SEMITEXA_REMOTE_DEPLOY_PATH',
            'SEMITEXA_REMOTE_DEPLOY_SSH_PORT',
            'SEMITEXA_REMOTE_DEPLOY_DOMAIN',
            'SEMITEXA_REMOTE_DEPLOY_USE_PASSWORD',
        ] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testLoadsRemoteDeployConfigFromEnvironment(): void
    {
        putenv('SEMITEXA_REMOTE_DEPLOY_TARGETS=deploy@203.0.113.10,root@example.com');
        putenv('SEMITEXA_REMOTE_DEPLOY_PATH=/srv/semitexa/demo');
        putenv('SEMITEXA_REMOTE_DEPLOY_SSH_PORT=2222');
        putenv('SEMITEXA_REMOTE_DEPLOY_DOMAIN=demo.example.com');
        putenv('SEMITEXA_REMOTE_DEPLOY_USE_PASSWORD=true');

        $config = (new RemoteDeployConfigLoader())->load('/tmp/my-project');

        self::assertCount(2, $config->targets);
        self::assertSame('/srv/semitexa/demo', $config->deployPath);
        self::assertSame(2222, $config->sshPort);
        self::assertSame('demo.example.com', $config->domain);
        self::assertTrue($config->preferPasswordAuth);
    }
}
