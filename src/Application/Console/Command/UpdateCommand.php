<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Discovery\DiscoveredPatch;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Update\Exception\UpdateException;
use Semitexa\Update\Domain\Model\OrchestratorPlanReport;
use Semitexa\Update\Domain\Model\OrchestratorStage;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the full update lifecycle: Pre patches → ORM schema sync → Apply patches → Post patches.
 *
 * The ORM schema-sync step is delegated to OrmMigrationGateway; this command never
 * issues DDL itself. If ORM is the only owner of schema migrations, this command is
 * the only operator-facing entry point that asks ORM to do its job.
 */
#[AsCommand(name: 'update', description: 'Run the full update sweep: data patches + ORM schema sync.')]
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute and print the plan without executing.')
            ->addOption('allow-destructive', null, InputOption::VALUE_NONE, 'Permit destructive ORM operations (DROP / type narrow).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = (string) ($input->getOption('connection') ?: 'default');
        $dryRun = (bool) $input->getOption('dry-run');
        $allowDestructive = (bool) $input->getOption('allow-destructive');

        try {
            $orchestrator = $this->runnerFactory->orchestrator($connection);
            $planReport = $orchestrator->plan();
        } catch (UpdateException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->renderPlan($io, $planReport);

        if ($dryRun) {
            try {
                $stages = $orchestrator->run(allowDestructive: $allowDestructive, dryRun: true);
            } catch (UpdateException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
            $this->renderStages($io, $stages);
            $io->note('Dry-run: no patches and no DDL were executed.');
            return Command::SUCCESS;
        }

        try {
            $stages = $orchestrator->run(allowDestructive: $allowDestructive, dryRun: false);
        } catch (UpdateException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->renderStages($io, $stages);

        foreach ($stages as $stage) {
            if (!$stage->isSuccess()) {
                $io->error(sprintf('Update aborted at stage "%s".', $stage->name));
                return Command::FAILURE;
            }
        }

        $io->success('Update completed.');
        return Command::SUCCESS;
    }

    private function renderPlan(SymfonyStyle $io, OrchestratorPlanReport $planReport): void
    {
        $io->title('Semitexa Update Plan');

        $io->section('ORM schema');
        $io->writeln('  ' . $planReport->schemaStatus->summary);
        if (!$planReport->schemaStatus->inSync) {
            $io->writeln(sprintf(
                '  Pending: %d operation(s)%s.',
                $planReport->schemaStatus->pendingOperations,
                $planReport->schemaStatus->destructiveOperations > 0
                    ? sprintf(' (%d destructive)', $planReport->schemaStatus->destructiveOperations)
                    : '',
            ));
        }

        foreach (UpdatePhase::order() as $phase) {
            $pending = $planReport->patchPlan->pendingByPhase[$phase->value] ?? [];
            if ($pending === []) {
                continue;
            }
            $io->section(sprintf('Patches: %s (pending: %d)', strtoupper($phase->value), count($pending)));
            $io->listing(array_map(
                static fn (DiscoveredPatch $p): string =>
                    $p->identity . ($p->description !== null && $p->description !== '' ? ' — ' . $p->description : ''),
                $pending,
            ));
        }

        $io->writeln(sprintf(
            'Summary: %d patches pending, %d already applied.',
            $planReport->patchPlan->pendingCount(),
            $planReport->patchPlan->appliedCount(),
        ));
    }

    /**
     * @param list<OrchestratorStage> $stages
     */
    private function renderStages(SymfonyStyle $io, array $stages): void
    {
        $io->newLine();
        $io->title('Stages');

        foreach ($stages as $stage) {
            $io->section($stage->name);
            if ($stage->syncResult !== null) {
                $io->writeln('  ' . $stage->syncResult->summary);
                continue;
            }
            $report = $stage->report;
            if ($report === null) {
                continue;
            }

            $io->writeln(sprintf(
                '  applied: %d  ·  skipped: %d  ·  duration: %dms',
                count($report->applied),
                count($report->skipped),
                $report->durationMs,
            ));

            foreach ($report->skipped as $skipped) {
                $io->writeln('    skipped ' . $skipped->identity . ':');
                foreach ($skipped->reasons as $reason) {
                    $io->writeln('      · ' . $reason);
                }
            }

            if (!$report->isSuccess()) {
                $io->writeln(sprintf(
                    '    <error>FAILED at %s: %s</error>',
                    (string) $report->failedIdentity,
                    (string) $report->failedError,
                ));
            }
        }
    }
}
