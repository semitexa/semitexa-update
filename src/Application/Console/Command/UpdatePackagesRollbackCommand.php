<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use JsonException;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Application\Service\Composer\InstalledReleaseSetRecorder;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentExecutor;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\DeploymentLogWriter;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\ProjectDeploymentConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Back to the release the last automatic deploy replaced. The systemd wrapper
 * runs it when the health check after its restart fails: a broken release
 * used to stay live, the next tick finding nothing newer to deploy.
 */
#[AsCommand(name: 'update:packages:rollback', description: 'Put back the composer state the last automatic deployment replaced')]
final class UpdatePackagesRollbackCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->getProjectRoot();
        $result = (new FrameworkDeploymentExecutor(new DeploymentLogWriter()))->rollbackLastDeploy(
            $projectRoot,
            (new ProjectDeploymentConfigLoader())->load()->restartCommand,
        );
        if ($result['status'] === 'rolled_back') {
            // The footer named the release backed out.
            try {
                $result['release_set'] = (new InstalledReleaseSetRecorder())->record($projectRoot);
            } catch (\Throwable) {
                $result['release_set'] = false;
            }
        }
        $code = $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;

        if ($input->getOption('json')) {
            try {
                $output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } catch (JsonException $e) {
                $output->writeln('<error>Failed to encode the result as JSON: ' . $e->getMessage() . '</error>');

                return Command::FAILURE;
            }

            return $code;
        }

        $io = new SymfonyStyle($input, $output);
        $io->definitionList(
            ['Status' => (string) $result['status']],
            ['Reason' => (string) $result['reason']],
        );
        if ($result['restart_required'] === true) {
            $io->note('Restart the stack (bin/semitexa server:restart) so every container runs the restored release.');
        }

        return $code;
    }
}
