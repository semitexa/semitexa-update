<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\RemoteBootstrap\Support;

use Semitexa\Update\Packaging\RemoteBootstrap\Data\RemoteDeployTarget;

final class RemoteDeployTargetParser
{
    /**
     * @return list<RemoteDeployTarget>
     */
    public function parseList(?string $rawTargets): array
    {
        if ($rawTargets === null || trim($rawTargets) === '') {
            return [];
        }

        $targets = [];
        foreach (preg_split('/\s*,\s*/', trim($rawTargets)) ?: [] as $rawTarget) {
            if ($rawTarget === '') {
                continue;
            }

            $targets[] = $this->parseOne($rawTarget);
        }

        return $targets;
    }

    public function parseOne(string $rawTarget): RemoteDeployTarget
    {
        $rawTarget = trim($rawTarget);
        if (!preg_match('/^(?<user>[a-zA-Z0-9._-]+)@(?<host>[a-zA-Z0-9.-]+)$/', $rawTarget, $matches)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid remote target "%s". Expected format: user@host',
                $rawTarget,
            ));
        }

        return new RemoteDeployTarget(
            user: $matches['user'],
            host: $matches['host'],
        );
    }
}
