<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use JsonException;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Application\Service\Composer\InstalledReleaseSetRecorder;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentExecutor;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentPlanner;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\DeploymentLogWriter;
use Semitexa\Update\Application\Service\RunJournalRepository;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update:packages:auto', description: 'Run automatic Semitexa framework deployment when enabled and updates are available')]
final class UpdatePackagesAutoCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected UpdateRunnerFactory $runnerFactory;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output deployment result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->getProjectRoot();
        $planner = new FrameworkDeploymentPlanner();
        $executor = new FrameworkDeploymentExecutor(new DeploymentLogWriter(), $this->runJournalOrNull());

        $plan = $planner->plan($projectRoot);
        $result = $executor->execute($projectRoot, $plan);
        // Every run, not only one that deployed: a noop still finds the
        // release a deploy before this code existed installed, and a
        // rolled-back one must not keep naming the release it backed out.
        $result['release_set'] = $this->recordReleaseSet($projectRoot);

        if ($input->getOption('json')) {
            try {
                $compactJson = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $output->writeln($compactJson, OutputInterface::OUTPUT_RAW);
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
            ['Installed release' => match ($result['release_set']) {
                false => 'unknown (not recorded)',
                null => 'none',
                default => (string) $result['release_set'],
            }],
        );

        if (($result['restart_status'] ?? null) !== null) {
            $io->text('Restart: ' . $result['restart_status']);
        }

        if (($result['run_journal'] ?? null) !== null) {
            $io->text('Run journal: ' . $result['run_journal']);
        }

        return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The release the footer names: the version, null when vendor/ holds no
     * whole release, false when it could not be found out or written. Never
     * fails the deployment: without a record the footer stays on core's tag.
     */
    private function recordReleaseSet(string $projectRoot): string|false|null
    {
        try {
            return (new InstalledReleaseSetRecorder())->record($projectRoot);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Auto-deploy may run on a host where the app DB is unreachable; the run
     * journal is then skipped and the JSON deployment log remains the record.
     */
    private function runJournalOrNull(): ?RunJournalRepository
    {
        try {
            return $this->runnerFactory->runJournal();
        } catch (\Throwable) {
            return null;
        }
    }
}
