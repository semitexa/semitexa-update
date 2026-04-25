<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Packaging\Releases\Support\ProjectDeploymentConfigLoader;

final class ProjectDeploymentConfigLoaderTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->unsetEnv('SEMITEXA_AUTO_DEPLOY_ENABLED');
        $this->unsetEnv('SEMITEXA_AUTO_DEPLOY_CHANNEL');
        $this->unsetEnv('SEMITEXA_AUTO_DEPLOY_SOURCE');
        $this->unsetEnv('SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL');
        $this->unsetEnv('SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL');
        $this->unsetEnv('SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND');
    }

    public function testLoadsAndNormalizesDeploymentConfigFromEnvironment(): void
    {
        putenv('SEMITEXA_AUTO_DEPLOY_ENABLED=true');
        putenv('SEMITEXA_AUTO_DEPLOY_CHANNEL=BETA');
        putenv('SEMITEXA_AUTO_DEPLOY_SOURCE=MiXeD');
        putenv('SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL=https://example.test/health');
        putenv('SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL=git@github.com:semitexa/releases.git');
        putenv('SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND=docker compose restart');

        $config = (new ProjectDeploymentConfigLoader())->load();

        self::assertTrue($config->enabled);
        self::assertSame('stable', $config->channel);
        self::assertSame('mixed', $config->sourceMode);
        self::assertSame('https://example.test/health', $config->healthcheckUrl);
        self::assertSame('git@github.com:semitexa/releases.git', $config->privateRepositoryUrl);
        self::assertSame('docker compose restart', $config->restartCommand);
    }

    private function unsetEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
