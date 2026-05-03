<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support;

use Semitexa\Core\Environment;
use Semitexa\Update\Domain\Model\RemoteDeployConfig;

final class RemoteDeployConfigLoader
{
    public function __construct(
        private readonly RemoteDeployTargetParser $targetParser = new RemoteDeployTargetParser(),
    ) {}

    public function load(string $projectRoot): RemoteDeployConfig
    {
        $defaultPath = '/srv/semitexa/' . basename($projectRoot);
        $targets = $this->targetParser->parseList(Environment::getEnvValue('SEMITEXA_REMOTE_DEPLOY_TARGETS', null));
        $deployPath = trim((string) Environment::getEnvValue('SEMITEXA_REMOTE_DEPLOY_PATH', $defaultPath));
        $domain = $this->nullable(Environment::getEnvValue('SEMITEXA_REMOTE_DEPLOY_DOMAIN', null));
        $sshPort = (int) Environment::getEnvValue('SEMITEXA_REMOTE_DEPLOY_SSH_PORT', '22');
        $preferPasswordAuth = strtolower((string) Environment::getEnvValue('SEMITEXA_REMOTE_DEPLOY_USE_PASSWORD', 'false')) === 'true';

        if ($sshPort < 1 || $sshPort > 65535) {
            $sshPort = 22;
        }

        return new RemoteDeployConfig(
            targets: $targets,
            deployPath: $deployPath !== '' ? $deployPath : $defaultPath,
            sshPort: $sshPort,
            domain: $domain,
            preferPasswordAuth: $preferPasswordAuth,
        );
    }

    private function nullable(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;
        return $value === '' ? null : $value;
    }
}
