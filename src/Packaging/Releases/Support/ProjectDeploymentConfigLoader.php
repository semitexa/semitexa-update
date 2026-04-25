<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\Releases\Support;

use Semitexa\Core\Environment;
use Semitexa\Update\Packaging\Releases\Data\DeploymentConfig;

final class ProjectDeploymentConfigLoader
{
    public function load(): DeploymentConfig
    {
        $enabled = strtolower((string) Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_ENABLED', 'false')) === 'true';
        $channel = strtolower((string) Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_CHANNEL', 'stable'));
        $sourceMode = strtolower((string) Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_SOURCE', 'packagist'));
        $healthcheckUrl = $this->nullable(Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL', null));
        $privateRepositoryUrl = $this->nullable(Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_PRIVATE_REPOSITORY_URL', null));
        $restartCommand = $this->nullable(Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_RESTART_COMMAND', null));

        if (!in_array($channel, ['stable'], true)) {
            $channel = 'stable';
        }

        if (!in_array($sourceMode, ['packagist', 'private', 'mixed'], true)) {
            $sourceMode = 'packagist';
        }

        return new DeploymentConfig(
            enabled: $enabled,
            channel: $channel,
            sourceMode: $sourceMode,
            healthcheckUrl: $healthcheckUrl,
            privateRepositoryUrl: $privateRepositoryUrl,
            restartCommand: $restartCommand,
        );
    }

    private function nullable(?string $value): ?string
    {
        $trimmed = $value !== null ? trim($value) : null;
        return $trimmed !== '' ? $trimmed : null;
    }
}
