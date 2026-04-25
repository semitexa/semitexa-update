<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\RemoteBootstrap\Support;

final class RemoteBootstrapLogWriter
{
    /**
     * @param array<string, mixed> $payload
     */
    public function write(string $projectRoot, array $payload): string
    {
        $logDir = $projectRoot . '/var/log/deployments';
        if (!is_dir($logDir) && !mkdir($logDir, 0777, true) && !is_dir($logDir)) {
            throw new \RuntimeException('Failed to create remote deployment log directory.');
        }

        $path = sprintf('%s/remote-bootstrap-%s.json', $logDir, gmdate('YmdHis'));
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode remote bootstrap log.');
        }

        file_put_contents($path, $json . "\n");
        return $path;
    }
}
