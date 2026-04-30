<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support;

use Semitexa\Update\Domain\Model\RemoteDeployTarget;
use Semitexa\Update\Domain\Model\RemotePathState;

final class RemotePathInspector
{
    public function __construct(
        private readonly RemoteSshClient $sshClient = new RemoteSshClient(),
    ) {}

    public function inspect(RemoteDeployTarget $target, int $port, string $path, ?string $password = null): RemotePathState
    {
        $remoteCommand = sprintf(
            'PATH_TO_CHECK=%s; ' .
            'if [ -d "$PATH_TO_CHECK" ]; then echo EXISTS=1; else echo EXISTS=0; fi; ' .
            'if [ -n "$(find "$PATH_TO_CHECK" -mindepth 1 -maxdepth 1 2>/dev/null | head -n 1)" ]; then echo HAS_FILES=1; else echo HAS_FILES=0; fi; ' .
            'if [ -f "$PATH_TO_CHECK/.semitexa-deployment.json" ]; then echo HAS_MARKER=1; else echo HAS_MARKER=0; fi; ' .
            'if [ -f "$PATH_TO_CHECK/docker-compose.yml" ]; then echo HAS_COMPOSE=1; else echo HAS_COMPOSE=0; fi',
            escapeshellarg($path),
        );

        $result = $this->sshClient->run($target, $port, $remoteCommand, $password);
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Failed to inspect remote deployment path: ' . $result->stderr);
        }

        $values = [];
        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $value;
        }

        return new RemotePathState(
            path: $path,
            exists: ($values['EXISTS'] ?? '0') === '1',
            hasFiles: ($values['HAS_FILES'] ?? '0') === '1',
            hasMarker: ($values['HAS_MARKER'] ?? '0') === '1',
            hasComposeFile: ($values['HAS_COMPOSE'] ?? '0') === '1',
        );
    }
}
