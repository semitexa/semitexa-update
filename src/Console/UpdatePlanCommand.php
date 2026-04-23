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

#[AsCommand(name: 'update:plan', description: 'Compute the update plan without executing anything.')]
final class UpdatePlanCommand extends BaseCommand
{
    public function __construct(
        private readonly UpdateRunnerFactory $runnerFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Connection name for the journal', 'default');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = (string) ($input->getOption('connection') ?: 'default');

        try {
            $plan = $this->runnerFactory->create($connection)->plan();
        } catch (UpdateException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->render($io, $plan);

        return Command::SUCCESS;
    }

    private function render(SymfonyStyle $io, Plan $plan): void
    {
        $io->title('Semitexa Update Plan');

        if ($plan->isEmpty()) {
            $io->success('Nothing to update. All discovered steps are applied.');
            $io->writeln(sprintf('Applied: %d step(s).', $plan->appliedCount()));
            return;
        }

        foreach (UpdatePhase::order() as $phase) {
            $pending = $plan->pendingByPhase[$phase->value] ?? [];
            if ($pending === []) {
                continue;
            }
            $io->section(sprintf('%s  (pending: %d)', strtoupper($phase->value), count($pending)));
            $io->listing(array_map(
                static fn (DiscoveredStep $s): string => self::describe($s),
                $pending,
            ));
        }

        $io->writeln(sprintf(
            'Summary: %d pending, %d already applied.',
            $plan->pendingCount(),
            $plan->appliedCount(),
        ));
    }

    private static function describe(DiscoveredStep $step): string
    {
        $line = $step->fqcn;
        if ($step->description !== null && $step->description !== '') {
            $line .= ' — ' . $step->description;
        }
        return $line;
    }
}
