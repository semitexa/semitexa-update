<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Domain\Enum\PatchStatus;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Update\Exception\UpdateException;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update:status', description: 'Show current update status (applied / pending / failed counts).')]
final class UpdateStatusCommand extends BaseCommand
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

        $io->title('Semitexa Update Status');

        $rows = [];
        foreach (UpdatePhase::order() as $phase) {
            $pending = $plan->pendingByPhase[$phase->value] ?? [];
            $applied = $plan->appliedByPhase[$phase->value] ?? [];
            $rows[] = [$phase->value, count($applied), count($pending)];
        }
        $io->table(['Phase', 'Applied', 'Pending'], $rows);

        $failed = [];
        $lastApplied = null;
        foreach ($plan->journalByIdentity as $entry) {
            if ($entry->status === PatchStatus::Failed) {
                $failed[] = $entry;
            }
            if ($entry->status === PatchStatus::Applied) {
                if ($lastApplied === null || $entry->completedAt > $lastApplied->completedAt) {
                    $lastApplied = $entry;
                }
            }
        }

        if ($lastApplied !== null) {
            $io->writeln(sprintf(
                'Last applied: %s at %s (%dms).',
                $lastApplied->identity(),
                (string) $lastApplied->completedAt,
                (int) $lastApplied->durationMs,
            ));
        } else {
            $io->writeln('No patches have been applied yet.');
        }

        if ($failed !== []) {
            $io->warning(sprintf('%d patch(es) in failed state — operator intervention required:', count($failed)));
            foreach ($failed as $entry) {
                $io->writeln(sprintf('  - %s : %s', $entry->identity(), (string) $entry->error));
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
