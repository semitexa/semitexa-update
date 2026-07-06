<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use JsonException;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentPlanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update:packages:check', description: 'Inspect Semitexa auto deployment status and discover newer stable releases')]
final class UpdatePackagesCheckCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output deployment status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $plan = (new FrameworkDeploymentPlanner())->plan($this->getProjectRoot());

        if ($input->getOption('json')) {
            try {
                $payload = [
                    'enabled' => $plan->config->enabled,
                    'channel' => $plan->config->channel,
                    'source_mode' => $plan->config->sourceMode,
                    'healthcheck_url' => $plan->config->healthcheckUrl,
                    'private_repository_url' => $plan->config->privateRepositoryUrl,
                    'selected_version' => $plan->selectedVersion,
                    'private_latest_version' => $plan->privateLatestVersion,
                    'update_available' => $plan->updateAvailable,
                    'reason' => $plan->reason,
                    'installed_packages' => $plan->installedPackages,
                    'package_updates' => array_map(
                        static fn($update) => [
                            'package' => $update->packageName,
                            'installed_version' => $update->installedVersion,
                            'latest_version' => $update->latestVersion,
                            'source' => $update->source,
                        ],
                        $plan->packageUpdates,
                    ),
                    'local_workspace_packages' => array_map(
                        static fn($pkg) => [
                            'package' => $pkg->name,
                            'version' => $pkg->version,
                            'source_url' => $pkg->sourceUrl,
                        ],
                        array_values($plan->localWorkspacePackages),
                    ),
                    'source_warnings' => $plan->sourceWarnings,
                ];

                $compactJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $output->writeln($compactJson);
                return Command::SUCCESS;
            } catch (JsonException $e) {
                $output->writeln('<error>Failed to encode deployment status as JSON: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Semitexa Auto Deployment');
        $io->definitionList(
            ['Enabled' => $plan->config->enabled ? 'yes' : 'no'],
            ['Channel' => $plan->config->channel],
            ['Source mode' => $plan->config->sourceMode],
            ['Healthcheck URL' => $plan->config->healthcheckUrl ?? 'not configured'],
            ['Private repository' => $plan->config->privateRepositoryUrl ?? 'not configured'],
            ['Selected version' => $plan->selectedVersion ?? 'none'],
            ['Reason' => $plan->reason],
        );

        if ($plan->packageUpdates !== []) {
            $rows = [];
            foreach ($plan->packageUpdates as $update) {
                $rows[] = [$update->packageName, $update->installedVersion, $update->latestVersion, $update->source];
            }
            $io->section('Package updates');
            $io->table(['Package', 'Installed', 'Latest', 'Source'], $rows);
        }

        if ($plan->localWorkspacePackages !== []) {
            $rows = [];
            foreach ($plan->localWorkspacePackages as $pkg) {
                $rows[] = [$pkg->name, $pkg->version, $pkg->sourceUrl];
            }
            $io->section('Local workspace packages (skipped — not Composer-updateable)');
            $io->table(['Package', 'Version', 'Source path'], $rows);
        }

        if ($plan->privateLatestVersion !== null) {
            $io->section('Private repository');
            $io->text('Latest stable tag: ' . $plan->privateLatestVersion);
        }

        if ($plan->sourceWarnings !== []) {
            $io->warning(array_merge(
                ['Release discovery was DEGRADED — "no update" below may hide a newer release:'],
                $plan->sourceWarnings,
            ));
        }

        return Command::SUCCESS;
    }
}
