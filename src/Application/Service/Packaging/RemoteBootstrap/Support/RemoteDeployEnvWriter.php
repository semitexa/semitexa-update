<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\RemoteBootstrap\Support;

final class RemoteDeployEnvWriter
{
    public function appendTarget(string $projectRoot, string $target): void
    {
        $envPath = $projectRoot . '/.env';
        $existing = is_file($envPath) ? (string) file_get_contents($envPath) : '';

        if (preg_match('/^SEMITEXA_REMOTE_DEPLOY_TARGETS=(.*)$/m', $existing, $matches) === 1) {
            $current = trim($matches[1]);
            $targets = array_values(array_filter(preg_split('/\s*,\s*/', trim($current)) ?: []));
            if (!in_array($target, $targets, true)) {
                $targets[] = $target;
            }

            $replacement = 'SEMITEXA_REMOTE_DEPLOY_TARGETS=' . implode(',', $targets);
            $updated = preg_replace('/^SEMITEXA_REMOTE_DEPLOY_TARGETS=.*$/m', $replacement, $existing);
            file_put_contents($envPath, $updated);
            return;
        }

        $suffix = $existing !== '' && !str_ends_with($existing, "\n") ? "\n" : '';
        $line = 'SEMITEXA_REMOTE_DEPLOY_TARGETS=' . $target . "\n";
        file_put_contents($envPath, $existing . $suffix . $line);
    }
}
