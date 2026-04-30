<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use JsonException;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentExecutor;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentPlanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update:packages:auto', description: 'Run automatic Semitexa framework deployment when enabled and updates are available')]
final class UpdatePackagesAutoCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output deployment result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->getProjectRoot();
        $planner = new FrameworkDeploymentPlanner();
        $executor = new FrameworkDeploymentExecutor();

        $plan = $planner->plan($projectRoot);
        $result = $executor->execute($projectRoot, $plan);

        if ($input->getOption('json')) {
            try {
                $compactJson = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $output->writeln($compactJson);
                return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
            } catch (JsonException $e) {
                $output->writeln('<error>Failed to encode deployment result as JSON: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Semitexa Automatic Deployment');
        $io->definitionList(
            ['Status' => (string) $result['status']],
            ['Reason' => (string) $result['reason']],
            ['Selected version' => (string) ($result['selected_version'] ?? 'none')],
            ['Source mode' => (string) ($result['source_mode'] ?? 'unknown')],
            ['Release channel' => (string) ($result['release_channel'] ?? 'unknown')],
        );

        if (($result['restart_status'] ?? null) !== null) {
            $io->text('Restart: ' . $result['restart_status']);
        }

        return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
