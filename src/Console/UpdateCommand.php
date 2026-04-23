<?php

declare(strict_types=1);

namespace Semitexa\Update\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Update\Discovery\DiscoveredStep;
use Semitexa\Update\Enum\UpdatePhase;
use Semitexa\Update\Exception\UpdateException;
use Semitexa\Update\Planner\Plan;
use Semitexa\Update\Runner\UpdateRunnerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update', description: 'Apply pending update steps in phase + DAG order.')]
final class UpdateCommand extends BaseCommand
{
    public function __construct(
        private readonly UpdateRunnerFactory $runnerFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Connection name for the journal', 'default')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute and print the plan without executing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = (string) ($input->getOption('connection') ?: 'default');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $runner = $this->runnerFactory->create($connection);
            $plan = $runner->plan();
        } catch (UpdateException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($plan->isEmpty()) {
            $io->success('Nothing to update.');
            return Command::SUCCESS;
        }

        $this->renderPlan($io, $plan);

        if ($dryRun) {
            $io->note('Dry-run: no steps were executed.');
            return Command::SUCCESS;
        }

        $report = $runner->run($plan);

        $io->newLine();
        if ($report->isSuccess()) {
            $io->success(sprintf(
                'Update completed: %d step(s) applied in %dms.',
                count($report->applied),
                $report->durationMs,
            ));
            return Command::SUCCESS;
        }

        $io->error(sprintf(
            "Update failed at step %s after %d successful step(s).\nError: %s",
            (string) $report->failedFqcn,
            count($report->applied),
            (string) $report->failedError,
        ));
        return Command::FAILURE;
    }

    private function renderPlan(SymfonyStyle $io, Plan $plan): void
    {
        $io->title('Semitexa Update Plan');

        foreach (UpdatePhase::order() as $phase) {
            $pending = $plan->pendingByPhase[$phase->value] ?? [];
            if ($pending === []) {
                continue;
            }
            $io->section(sprintf('%s  (pending: %d)', strtoupper($phase->value), count($pending)));
            $io->listing(array_map(
                static fn (DiscoveredStep $s): string =>
                    $s->fqcn . ($s->description !== null && $s->description !== '' ? ' — ' . $s->description : ''),
                $pending,
            ));
        }

        $io->writeln(sprintf(
            'Summary: %d pending, %d already applied.',
            $plan->pendingCount(),
            $plan->appliedCount(),
        ));
    }
}
