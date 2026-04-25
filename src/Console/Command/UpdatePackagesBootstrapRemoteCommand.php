<?php

declare(strict_types=1);

namespace Semitexa\Update\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Update\Packaging\RemoteBootstrap\Data\RemoteDeployTarget;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\LocalPrerequisiteChecker;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteArtifactBuilder;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteBootstrapLogWriter;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteDeployConfigLoader;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteDeployEnvBuilder;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteDeployEnvWriter;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteDeployTargetParser;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteOsReleaseParser;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemotePathInspector;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteScenarioResolver;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteSshClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update:packages:bootstrap-remote', description: 'Validate and prepare a first remote Semitexa deployment target (Ubuntu 20.04+ only)')]
final class UpdatePackagesBootstrapRemoteCommand extends BaseCommand
{
    /**
     * @var list<string>
     */
    private array $completedSteps = [];

    protected function configure(): void
    {
        $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Explicit remote target in user@host format')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Remote deployment path override')
            ->addOption('force-reinitialize', null, InputOption::VALUE_NONE, 'Allow bootstrap validation against an already initialized remote path')
            ->addOption('remote-env-file', null, InputOption::VALUE_REQUIRED, 'Reserved for future remote production env file support')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output remote bootstrap preflight result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->completedSteps = [];
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->getProjectRoot();
        $config = (new RemoteDeployConfigLoader())->load($projectRoot);
        $target = $this->resolveTarget($input, $io, $projectRoot, $config->targets);
        $path = trim((string) ($input->getOption('path') ?: $config->deployPath));
        $result = [
            'status' => 'blocked',
            'phase' => 'phase-1-preflight',
            'target' => $target->toConnectionString(),
            'path' => $path,
            'completed_steps' => [],
        ];
        $temporaryFiles = [];
        $remoteWorkspace = null;

        try {
            $this->assertInteractive($input);
            $this->confirmDestructiveIntent($input, $io, $target, $path);
            $this->markStep('destructive_confirmation');

            $checker = new LocalPrerequisiteChecker();
            $missing = $checker->missingBaseTools();
            if ($missing !== []) {
                throw new \RuntimeException('Missing local prerequisites: ' . implode(', ', $missing));
            }
            $this->markStep('local_prerequisites');

            $sshClient = new RemoteSshClient();
            $password = null;
            $probe = $sshClient->probeBatchAuth($target, $config->sshPort);
            $authMode = 'ssh-key';

            if (!$probe->isSuccess()) {
                if (!$sshClient->requiresPassword($probe)) {
                    throw new \RuntimeException('SSH connection failed: ' . $this->bestError($probe->stderr, $probe->stdout));
                }

                if (!$checker->hasSshpass()) {
                    throw new \RuntimeException('SSH password auth is required, but sshpass is not installed locally.');
                }

                $password = $io->askHidden('SSH password for ' . $target->toConnectionString());
                if ($password === null || $password === '') {
                    throw new \RuntimeException('SSH password entry was cancelled.');
                }

                $passwordProbe = $sshClient->run($target, $config->sshPort, 'printf __SEMITEXA__', $password);
                if (!$passwordProbe->isSuccess()) {
                    throw new \RuntimeException('SSH password authentication failed: ' . $this->bestError($passwordProbe->stderr, $passwordProbe->stdout));
                }

                $authMode = 'password';
            }
            $result['auth_mode'] = $authMode;
            $this->markStep('ssh_authentication');

            $osResult = $sshClient->run($target, $config->sshPort, 'cat /etc/os-release', $password);
            if (!$osResult->isSuccess()) {
                throw new \RuntimeException('Failed to read remote OS information: ' . $this->bestError($osResult->stderr, $osResult->stdout));
            }

            $osInfo = (new RemoteOsReleaseParser())->parse($osResult->stdout);
            $scenarioPath = (new RemoteScenarioResolver())->resolve($osInfo, dirname(__DIR__, 3));
            $pathState = (new RemotePathInspector($sshClient))->inspect($target, $config->sshPort, $path, $password);

            if ($pathState->isInitialized() && !$input->getOption('force-reinitialize')) {
                throw new \RuntimeException(sprintf(
                    'Remote path "%s" already looks initialized. Re-run with --force-reinitialize only if you intend to destroy existing deployment state.',
                    $path,
                ));
            }
            $this->markStep('remote_preflight');

            if ($pathState->isInitialized()) {
                $confirmed = $io->confirm(
                    sprintf('Remote path "%s" already looks initialized. Continue with force-reinitialize intent?', $path),
                    false,
                );
                if (!$confirmed) {
                    throw new \RuntimeException('Remote reinitialization was cancelled by the operator.');
                }
            }

            $artifact = (new RemoteArtifactBuilder())->build($projectRoot);
            $temporaryFiles[] = $artifact->path;
            $this->markStep('artifact_built');

            $remoteEnvFile = (new RemoteDeployEnvBuilder())->build(
                is_string($input->getOption('remote-env-file')) ? $input->getOption('remote-env-file') : null,
                $config->domain,
            );
            $temporaryFiles[] = $remoteEnvFile;
            $this->markStep('remote_env_prepared');

            $scenarioId = sprintf('%s/%s', $osInfo->id, (new RemoteScenarioResolver())->normalizeUbuntuVersion($osInfo->versionId));
            $remoteWorkspace = sprintf(
                '/tmp/semitexa-remote-bootstrap-%s-%s',
                gmdate('YmdHis'),
                bin2hex(random_bytes(4)),
            );

            $this->runRemoteCommand(
                $sshClient,
                $target,
                $config->sshPort,
                sprintf('mkdir -p %s', escapeshellarg($remoteWorkspace)),
                $password,
                'Failed to create remote bootstrap workspace.',
            );
            $this->markStep('remote_workspace_created');

            $remoteArtifactPath = $remoteWorkspace . '/' . basename($artifact->path);
            $remoteBootstrapScriptPath = $remoteWorkspace . '/bootstrap.sh';
            $remoteVerifyScriptPath = $remoteWorkspace . '/verify.sh';
            $remoteEnvPath = $remoteWorkspace . '/remote.env';

            $this->uploadFile($sshClient, $target, $config->sshPort, $artifact->path, $remoteArtifactPath, $password, 'Failed to upload deploy artifact.');
            $this->uploadFile($sshClient, $target, $config->sshPort, $scenarioPath . '/bootstrap.sh', $remoteBootstrapScriptPath, $password, 'Failed to upload remote bootstrap script.');
            $this->uploadFile($sshClient, $target, $config->sshPort, $scenarioPath . '/verify.sh', $remoteVerifyScriptPath, $password, 'Failed to upload remote verify script.');
            $this->uploadFile($sshClient, $target, $config->sshPort, $remoteEnvFile, $remoteEnvPath, $password, 'Failed to upload remote env file.');
            $this->markStep('remote_assets_uploaded');

            $this->runRemoteCommand(
                $sshClient,
                $target,
                $config->sshPort,
                sprintf(
                    'chmod 755 %s %s',
                    escapeshellarg($remoteBootstrapScriptPath),
                    escapeshellarg($remoteVerifyScriptPath),
                ),
                $password,
                'Failed to make remote scenario scripts executable.',
            );

            $bootstrapCommand = $this->buildScenarioCommand(
                [
                    'SEMITEXA_DEPLOY_PATH' => $path,
                    'SEMITEXA_ARTIFACT_PATH' => $remoteArtifactPath,
                    'SEMITEXA_REMOTE_ENV_PATH' => $remoteEnvPath,
                    'SEMITEXA_FORCE_REINITIALIZE' => $pathState->isInitialized() ? '1' : '0',
                    'SEMITEXA_SCENARIO_ID' => $scenarioId,
                    'SEMITEXA_DEPLOY_DOMAIN' => $config->domain ?? '',
                ],
                $remoteBootstrapScriptPath,
            );
            $this->runRemoteCommand(
                $sshClient,
                $target,
                $config->sshPort,
                $bootstrapCommand,
                $password,
                'Remote bootstrap scenario failed.',
            );
            $this->markStep('remote_bootstrap_completed');

            $verifyCommand = $this->buildScenarioCommand(
                [
                    'SEMITEXA_DEPLOY_PATH' => $path,
                    'SEMITEXA_SCENARIO_ID' => $scenarioId,
                ],
                $remoteVerifyScriptPath,
            );
            $this->runRemoteCommand(
                $sshClient,
                $target,
                $config->sshPort,
                $verifyCommand,
                $password,
                'Remote verification failed.',
            );
            $this->markStep('remote_verification_completed');

            $this->runRemoteCommand(
                $sshClient,
                $target,
                $config->sshPort,
                sprintf('rm -rf %s', escapeshellarg($remoteWorkspace)),
                $password,
                'Failed to clean remote bootstrap workspace.',
            );
            $this->markStep('remote_workspace_cleaned');

            $result = [
                'status' => 'deployed',
                'phase' => 'phase-8-remote-bootstrap-complete',
                'target' => $target->toConnectionString(),
                'path' => $path,
                'auth_mode' => $authMode,
                'remote_os' => [
                    'id' => $osInfo->id,
                    'version_id' => $osInfo->versionId,
                    'pretty_name' => $osInfo->prettyName,
                ],
                'scenario_path' => $scenarioPath,
                'scenario_id' => $scenarioId,
                'path_state' => [
                    'exists' => $pathState->exists,
                    'has_files' => $pathState->hasFiles,
                    'has_marker' => $pathState->hasMarker,
                    'has_compose_file' => $pathState->hasComposeFile,
                ],
                'artifact' => [
                    'path' => $artifact->path,
                    'size_bytes' => $artifact->sizeBytes,
                    'sha256' => $artifact->sha256,
                ],
                'completed_steps' => $this->completedSteps,
                'reason' => 'Remote first deployment completed. The project artifact was uploaded, bootstrapped on the remote Ubuntu host, and verified.',
            ];
            $result['log_path'] = (new RemoteBootstrapLogWriter())->write($projectRoot, $result);

            if ($input->getOption('json')) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::SUCCESS;
            }

            $io->title('Semitexa Remote First Deployment');
            $io->warning('This command is for first deployment only. It is not a safe in-place update mechanism.');
            $io->definitionList(
                ['Target' => $result['target']],
                ['Path' => $result['path']],
                ['Auth mode' => $result['auth_mode']],
                ['Remote OS' => ($osInfo->prettyName ?? ($osInfo->id . ' ' . $osInfo->versionId))],
                ['Scenario' => $result['scenario_path']],
                ['Phase' => $result['phase']],
                ['Artifact' => $artifact->path],
                ['Log' => $result['log_path']],
            );
            $io->text($result['reason']);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $result['reason'] = $e->getMessage();
            $result['completed_steps'] = $this->completedSteps;
            if ($remoteWorkspace !== null) {
                $result['remote_workspace'] = $remoteWorkspace;
            }
            try {
                $result['log_path'] = (new RemoteBootstrapLogWriter())->write($projectRoot, $result);
            } catch (\Throwable) {
            }

            if ($input->getOption('json')) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return Command::FAILURE;
            }

            $io->error($e->getMessage());
            return Command::FAILURE;
        } finally {
            foreach ($temporaryFiles as $pathToDelete) {
                if (is_string($pathToDelete) && is_file($pathToDelete)) {
                    @unlink($pathToDelete);
                }
            }
        }
    }

    /**
     * @param list<RemoteDeployTarget> $configuredTargets
     */
    private function resolveTarget(InputInterface $input, SymfonyStyle $io, string $projectRoot, array $configuredTargets): RemoteDeployTarget
    {
        $parser = new RemoteDeployTargetParser();
        $optionTarget = $input->getOption('target');
        if (is_string($optionTarget) && trim($optionTarget) !== '') {
            return $parser->parseOne($optionTarget);
        }

        if ($configuredTargets === []) {
            $this->assertInteractive($input);
            $raw = $io->ask('No remote deploy target is configured. Enter a target in user@host format');
            if (!is_string($raw) || trim($raw) === '') {
                throw new \RuntimeException('Remote deploy target was not provided.');
            }

            $target = $parser->parseOne($raw);
            if ($io->confirm('Append this target to .env for future use?', true)) {
                (new RemoteDeployEnvWriter())->appendTarget($projectRoot, $target->toConnectionString());
            }

            return $target;
        }

        if (count($configuredTargets) === 1) {
            return $configuredTargets[0];
        }

        $this->assertInteractive($input);
        $choices = array_map(
            static fn(RemoteDeployTarget $target): string => $target->toConnectionString(),
            $configuredTargets,
        );
        $selected = $io->choice('Select a remote target for first deployment', $choices);
        return $parser->parseOne($selected);
    }

    private function confirmDestructiveIntent(InputInterface $input, SymfonyStyle $io, RemoteDeployTarget $target, string $path): void
    {
        $io->warning([
            'Remote first deployment is destructive.',
            'This command is not an update workflow.',
            sprintf('Running it against an already-used target may overwrite files or destroy data under "%s".', $path),
            sprintf('Selected target: %s', $target->toConnectionString()),
        ]);

        $typed = $io->ask('Type "DEPLOY NEW SERVER" to continue');
        if ($typed !== 'DEPLOY NEW SERVER') {
            throw new \RuntimeException('Remote bootstrap confirmation failed.');
        }
    }

    private function assertInteractive(InputInterface $input): void
    {
        if (!$input->isInteractive()) {
            throw new \RuntimeException('update:packages:bootstrap-remote requires an interactive TTY in phase 1.');
        }
    }

    private function bestError(string $stderr, string $stdout): string
    {
        return trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    }

    private function markStep(string $step): void
    {
        $this->completedSteps[] = $step;
    }

    /**
     * @param array<string, string> $environment
     */
    private function buildScenarioCommand(array $environment, string $scriptPath): string
    {
        $prefix = [];
        foreach ($environment as $key => $value) {
            $prefix[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }

        return implode(' ', $prefix) . ' bash ' . escapeshellarg($scriptPath);
    }

    private function runRemoteCommand(
        RemoteSshClient $sshClient,
        RemoteDeployTarget $target,
        int $port,
        string $command,
        ?string $password,
        string $failureMessage,
    ): void {
        $result = $sshClient->run($target, $port, $command, $password);
        if (!$result->isSuccess()) {
            throw new \RuntimeException($failureMessage . ' ' . $this->bestError($result->stderr, $result->stdout));
        }
    }

    private function uploadFile(
        RemoteSshClient $sshClient,
        RemoteDeployTarget $target,
        int $port,
        string $localPath,
        string $remotePath,
        ?string $password,
        string $failureMessage,
    ): void {
        $result = $sshClient->upload($target, $port, $localPath, $remotePath, $password);
        if (!$result->isSuccess()) {
            throw new \RuntimeException($failureMessage . ' ' . $this->bestError($result->stderr, $result->stdout));
        }
    }
}
