<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support;

use Semitexa\Update\Domain\Model\RemoteDeployTarget;

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
        // An IPv6 address in brackets (user@[2001:db8::10]): bare, its
        // colons could not be told from a port or an scp path.
        if (preg_match('/^(?<user>[a-zA-Z0-9._-]+)@(?:(?<host>[a-zA-Z0-9.-]+)|\[(?<v6>[0-9a-fA-F:.]+)\])$/', $rawTarget, $matches) !== 1
            || (($matches['v6'] ?? '') !== '' && filter_var($matches['v6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid remote target "%s". Expected format: user@host or user@[IPv6 address]',
                $rawTarget,
            ));
        }

        return new RemoteDeployTarget(
            user: $matches['user'],
            host: ($matches['v6'] ?? '') !== '' ? strtolower($matches['v6']) : $matches['host'],
        );
    }
}
