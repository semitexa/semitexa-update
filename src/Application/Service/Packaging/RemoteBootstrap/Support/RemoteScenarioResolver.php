<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support;

use Semitexa\Update\Domain\Model\RemoteOsInfo;

final class RemoteScenarioResolver
{
    public function resolve(RemoteOsInfo $osInfo, string $packageRoot): string
    {
        if ($osInfo->id !== 'ubuntu') {
            throw new \RuntimeException(sprintf(
                'Unsupported remote OS "%s". Phase 1 supports Ubuntu 20.04+ only.',
                $osInfo->id,
            ));
        }

        $scenarioVersion = $this->normalizeUbuntuVersion($osInfo->versionId);
        $scenarioPath = $packageRoot . '/resources/remote-deploy/ubuntu/' . $scenarioVersion;

        if (!is_dir($scenarioPath)) {
            throw new \RuntimeException(sprintf(
                'Remote deployment scenario not found for ubuntu/%s.',
                $scenarioVersion,
            ));
        }

        return $scenarioPath;
    }

    public function normalizeUbuntuVersion(string $versionId): string
    {
        if (version_compare($versionId, '20.04', '<')) {
            throw new \RuntimeException('Unsupported Ubuntu version. Phase 1 supports Ubuntu 20.04+ only.');
        }

        if (version_compare($versionId, '24.04', '>=')) {
            return '24.04';
        }

        if (version_compare($versionId, '22.04', '>=')) {
            return '22.04';
        }

        return '20.04';
    }
}
