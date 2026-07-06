<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Service;

use Semitexa\Core\Environment;
use Semitexa\Update\Application\Service\HealthChecker;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Application\Service\UpdateLock;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Semitexa\Update\Domain\Model\DeploymentPlan;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\ComposerStateSnapshot;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\DeploymentLogWriter;

final class FrameworkDeploymentExecutor
{
    /**
     * $commandRunner overrides shell execution — tests inject a fake to
     * exercise partial-failure/rollback ordering without running composer.
     * Signature: fn (string $command, string $projectRoot): void, throw on
     * failure (same contract as run()).
     */
    public function __construct(
        private readonly DeploymentLogWriter $logWriter = new DeploymentLogWriter(),
        private readonly ?RunJournalRepository $runJournal = null,
        private readonly ?UpdateLock $lock = null,
        private readonly ?\Closure $commandRunner = null,
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

        if ($plan->sourceWarnings !== []) {
            $result['source_warnings'] = $plan->sourceWarnings;
        }

        $runId = null;
        $lock = null;
        $snapshot = null;
        $composerUpdated = false;
        try {
            if (!$plan->config->enabled) {
                return $this->finalize($projectRoot, $result, 'skipped');
            }

            if (!$plan->updateAvailable) {
                return $this->finalize($projectRoot, $result, 'noop');
            }

            // Same lock as the manual `update` sweep: never race a running
            // operator update from the auto-deploy timer (or vice versa).
            $lock = $this->lock ?? new UpdateLock($projectRoot);
            if (!$lock->acquire('auto-deploy')) {
                throw new \RuntimeException(sprintf(
                    'Another update run is already in progress (%s); auto-deploy will retry on the next tick.',
                    $lock->holderDescription(),
                ));
            }

            // Only actual deployment attempts enter the run journal; the
            // periodic timer's skipped/noop ticks would drown real history.
            $runId = $this->beginRunRecord($result);

            $snapshot = ComposerStateSnapshot::capture($projectRoot);

            $this->run($this->composerUpdateCommand($projectRoot), $projectRoot);
            $composerUpdated = true;

            $this->run($this->projectCliCommand($projectRoot, 'orm:sync'), $projectRoot);
            $this->run($this->projectCliCommand($projectRoot, 'cache:clear'), $projectRoot);

            $restartStatus = $this->restartRuntime($projectRoot, $plan->config->restartCommand);
            $result['restart_required'] = !$restartStatus['performed'];
            $result['restart_status'] = $restartStatus['message'];

            // Mandatory when configured: a deploy that cannot prove the app
            // answers is a failed deploy, restart or not.
            if ($plan->config->healthcheckUrl !== null) {
                $check = (new HealthChecker())->check($plan->config->healthcheckUrl);
                if (!$check->ok) {
                    throw new \RuntimeException($check->message);
                }
                $result['healthcheck'] = $check->message;
            } else {
                $result['healthcheck'] = 'skipped: SEMITEXA_AUTO_DEPLOY_HEALTHCHECK_URL not configured';
            }

            return $this->finalize($projectRoot, $result, 'updated', $plan, $runId);
        } catch (\Throwable $e) {
            $result['status'] = 'failed';
            $result['reason'] = $e->getMessage();
            if ($composerUpdated && $snapshot !== null) {
                $result['rollback'] = $this->rollback($projectRoot, $snapshot, $plan->config->restartCommand);
            }
            return $this->finalize($projectRoot, $result, 'failed', $plan, $runId);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Best-effort revert to the pre-update dependency state after a failure
     * that happened once vendor/ was already upgraded: restore composer
     * files, reinstall vendor from the restored lock, restart. The returned
     * string lands in the deployment log + run journal either way.
     */
    private function rollback(string $projectRoot, ComposerStateSnapshot $snapshot, ?string $restartCommand): string
    {
        if (!$snapshot->restoreFiles()) {
            return 'FAILED: could not restore composer.json/lock — system left on the new version; operator intervention required.';
        }

        try {
            $this->run($this->composerInstallCommand($projectRoot), $projectRoot);
        } catch (\Throwable $e) {
            return 'FAILED: composer files restored but composer install failed — vendor/ still on the new version: ' . $e->getMessage();
        }

        try {
            $this->restartRuntime($projectRoot, $restartCommand);
        } catch (\Throwable $e) {
            return 'partial: dependency state restored, but restart failed — restart manually: ' . $e->getMessage();
        }

        return 'performed: composer.json/lock and vendor/ restored to the pre-update state.';
    }

    private function composerInstallCommand(string $projectRoot): string
    {
        return sprintf(
            '%s install --prefer-dist --no-dev --no-interaction --optimize-autoloader --working-dir=%s',
            $this->composerBinary($projectRoot),
            escapeshellarg($projectRoot),
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function beginRunRecord(array &$result): ?string
    {
        if ($this->runJournal === null) {
            return null;
        }

        $actor = Environment::getEnvValue('SEMITEXA_UPDATE_ACTOR', null);
        $actor = is_string($actor) && trim($actor) !== '' ? trim($actor) : 'auto-deploy';

        try {
            return $this->runJournal->begin(RunJournalRepository::KIND_AUTO_DEPLOY, $actor, null);
        } catch (\Throwable $e) {
            $result['run_journal'] = 'unavailable: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function finishRunRecord(array &$result, string $status, DeploymentPlan $plan, ?string $runId): void
    {
        if ($this->runJournal === null || $runId === null) {
            return;
        }

        $deltas = [];
        foreach ($plan->packageUpdates as $update) {
            $deltas[$update->packageName] = [
                'from' => $update->installedVersion,
                'to'   => $update->latestVersion,
            ];
        }

        $outcome = $status === 'updated' ? RunOutcome::Success : RunOutcome::Failed;
        $stage = [
            'name'    => 'auto-deploy',
            'success' => $outcome === RunOutcome::Success,
            'message' => (string) ($result['restart_status'] ?? ''),
        ];
        if (isset($result['healthcheck'])) {
            $stage['healthcheck'] = (string) $result['healthcheck'];
        }
        if (isset($result['rollback'])) {
            $stage['rollback'] = (string) $result['rollback'];
        }
        $stages = [$stage];

        try {
            $this->runJournal->finish(
                $runId,
                $outcome,
                $outcome === RunOutcome::Failed ? 'auto-deploy' : null,
                $stages,
                $deltas,
                0,
                $outcome === RunOutcome::Failed ? (string) $result['reason'] : null,
            );
            $result['run_journal'] = 'recorded run ' . $runId;
        } catch (\Throwable $e) {
            $result['run_journal'] = 'unavailable: ' . $e->getMessage();
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

    private function run(string $command, string $projectRoot): void
    {
        if ($this->commandRunner !== null) {
            ($this->commandRunner)($command, $projectRoot);
            return;
        }

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
    private function finalize(string $projectRoot, array $result, string $status, ?DeploymentPlan $plan = null, ?string $runId = null): array
    {
        $result['status'] = $status;
        $result['finished_at'] = gmdate(DATE_ATOM);
        if ($plan !== null) {
            $this->finishRunRecord($result, $status, $plan, $runId);
        }
        $this->logWriter->write($projectRoot, $result);
        return $result;
    }
}
