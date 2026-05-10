<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Service;

use Semitexa\Core\Environment;
use Semitexa\Update\Domain\Model\DeploymentPlan;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\DeploymentLogWriter;

final class FrameworkDeploymentExecutor
{
    public function __construct(
        private readonly DeploymentLogWriter $logWriter = new DeploymentLogWriter(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $projectRoot, DeploymentPlan $plan): array
    {
        $startedAt = gmdate(DATE_ATOM);
        $result = [
            'started_at' => $startedAt,
            'finished_at' => null,
            'status' => 'skipped',
            'reason' => $plan->reason,
            'release_channel' => $plan->config->channel,
            'source_mode' => $plan->config->sourceMode,
            'selected_version' => $plan->selectedVersion,
            'private_latest_version' => $plan->privateLatestVersion,
            'installed_packages' => $plan->installedPackages,
            'updated_packages' => array_map(
                static fn($update) => [
                    'package' => $update->packageName,
                    'installed_version' => $update->installedVersion,
                    'latest_version' => $update->latestVersion,
                    'source' => $update->source,
                ],
                $plan->packageUpdates,
            ),
            'restart_required' => false,
        ];

        try {
            if (!$plan->config->enabled) {
                return $this->finalize($projectRoot, $result, 'skipped');
            }

            if (!$plan->updateAvailable) {
                return $this->finalize($projectRoot, $result, 'noop');
            }

            $this->run($this->composerUpdateCommand($projectRoot), $projectRoot);
            $this->run($this->projectCliCommand($projectRoot, 'orm:sync'), $projectRoot);
            $this->run($this->projectCliCommand($projectRoot, 'cache:clear'), $projectRoot);

            $restartStatus = $this->restartRuntime($projectRoot, $plan->config->restartCommand);
            $result['restart_required'] = !$restartStatus['performed'];
            $result['restart_status'] = $restartStatus['message'];

            if ($plan->config->healthcheckUrl !== null && $restartStatus['performed']) {
                $this->assertHealthy($plan->config->healthcheckUrl);
            }

            return $this->finalize($projectRoot, $result, 'updated');
        } catch (\Throwable $e) {
            $result['status'] = 'failed';
            $result['reason'] = $e->getMessage();
            return $this->finalize($projectRoot, $result, 'failed');
        }
    }

    private function composerUpdateCommand(string $projectRoot): string
    {
        // --prefer-dist: vendor/semitexa/* is shipped as dist (no .git/), but composer.json
        // declares vcs repositories, so without this flag composer will choose source mode
        // for some packages and fail with "GitDownloader.php line 155: The .git directory
        // is missing" the next time those packages upgrade.
        return sprintf(
            '%s update %s --with-all-dependencies --prefer-dist --no-dev --no-interaction --optimize-autoloader --working-dir=%s',
            $this->composerBinary($projectRoot),
            escapeshellarg('semitexa/*'),
            escapeshellarg($projectRoot),
        );
    }

    private function projectCliCommand(string $projectRoot, string $command): string
    {
        $cli = $projectRoot . '/vendor/bin/semitexa';
        if (!is_file($cli)) {
            throw new \RuntimeException('vendor/bin/semitexa not found. Run composer install before deployment.');
        }

        return sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg($cli), escapeshellarg($command));
    }

    private function composerBinary(string $projectRoot): string
    {
        $globalComposer = trim((string) shell_exec('command -v composer 2>/dev/null'));
        if ($globalComposer !== '') {
            return escapeshellarg($globalComposer);
        }

        $localComposer = $projectRoot . '/vendor/bin/composer';
        if (is_file($localComposer)) {
            return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($localComposer);
        }

        throw new \RuntimeException('Composer not found.');
    }

    /**
     * @return array{performed: bool, message: string}
     */
    private function restartRuntime(string $projectRoot, ?string $restartCommand): array
    {
        if ($restartCommand !== null) {
            $this->run($restartCommand, $projectRoot);
            return ['performed' => true, 'message' => 'Restart command executed from configuration.'];
        }

        // Do not attempt host-level compose restarts from inside a container.
        if (is_file($projectRoot . '/docker-compose.yml') && trim((string) shell_exec('command -v docker 2>/dev/null')) !== '' && !is_file('/.dockerenv')) {
            $this->run('docker compose restart', $projectRoot);
            return ['performed' => true, 'message' => 'Restarted docker compose services.'];
        }

        return ['performed' => false, 'message' => 'No restart command available; operator restart required.'];
    }

    private function assertHealthy(string $url): void
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "User-Agent: Semitexa-Dev-Auto-Deploy\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $statusCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1) {
            $statusCode = (int) $m[1];
        }

        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException("Health check failed for {$url}.");
        }
    }

    private function run(string $command, string $projectRoot): void
    {
        $fullCommand = sprintf(
            'cd %s && %s%s 2>&1',
            escapeshellarg($projectRoot),
            $this->shellEnvironmentPrefix(),
            $command,
        );
        $output = [];
        exec($fullCommand, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Command failed: {$command}\n" . implode("\n", $output));
        }
    }

    private function shellEnvironmentPrefix(): string
    {
        $exports = [];
        $environmentMap = [
            'HOME' => Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_HOME', null),
            'COMPOSER_HOME' => Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_COMPOSER_HOME', null),
            'GIT_SSH_COMMAND' => Environment::getEnvValue('SEMITEXA_AUTO_DEPLOY_GIT_SSH_COMMAND', null),
        ];

        foreach ($environmentMap as $name => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $exports[] = sprintf('export %s=%s', $name, escapeshellarg($value));
        }

        if ($exports === []) {
            return '';
        }

        return implode(' && ', $exports) . ' && ';
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function finalize(string $projectRoot, array $result, string $status): array
    {
        $result['status'] = $status;
        $result['finished_at'] = gmdate(DATE_ATOM);
        $this->logWriter->write($projectRoot, $result);
        return $result;
    }
}
