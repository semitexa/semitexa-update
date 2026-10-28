<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

final class DeploymentLogWriter
{
    /** The newest logs kept: a 15-minute timer writes 96 a day, and nothing removed one. */
    private const KEEP = 200;

    /**
     * @param array<string, mixed> $payload
     */
    public function write(string $projectRoot, array $payload): void
    {
        $dir = $projectRoot . '/var/log/deployments';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create deployment log directory: %s', $dir));
        }

        $path = sprintf('%s/%s.json', $dir, gmdate('Y-m-d\THis\Z'));
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . PHP_EOL) === false) {
            throw new \RuntimeException(sprintf('Failed to write deployment log file: %s', $path));
        }

        // Named by UTC time, so the name sorts by age; the remote bootstrap's
        // own logs in this directory are not these.
        $logs = glob(addcslashes($dir, '*?[') . '/[0-9]*Z.json') ?: [];
        sort($logs);
        foreach (array_slice($logs, 0, max(0, count($logs) - self::KEEP)) as $old) {
            @unlink($old);
        }
    }
}
