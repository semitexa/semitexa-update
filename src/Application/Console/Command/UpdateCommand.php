<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Discovery\DiscoveredPatch;
use Semitexa\Update\Domain\Enum\PackageDriftStatus;
use Semitexa\Update\Domain\Enum\ScaffoldSyncAction;
use Semitexa\Update\Domain\Enum\ScaffoldSyncOutcome;
use Semitexa\Update\Domain\Enum\ScaffoldSyncStatus;
use Semitexa\Update\Domain\Enum\UpdatePhase;
use Semitexa\Update\Domain\Model\PackageDrift\PackageDriftReport;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncPlan;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSyncReport;
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
    #[InjectAsReadonly]
    protected UpdateRunnerFactory $runnerFactory;

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

        if ($planReport->packageDrift !== null) {
            $this->renderPackageDrift($io, $planReport->packageDrift);
        }

        if ($planReport->scaffoldPlan !== null) {
            $this->renderScaffoldPlan($io, $planReport->scaffoldPlan);
        }

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
            if ($stage->scaffoldReport !== null) {
                $this->renderScaffoldStage($io, $stage->scaffoldReport);
                continue;
            }
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

    private function renderPackageDrift(SymfonyStyle $io, PackageDriftReport $drift): void
    {
        $io->section('Composer package drift (read-only)');

        if (!$drift->releaseSetCoherent) {
            $io->writeln(sprintf(
                '  <comment>! semitexa/* set spans multiple release dates: %s</comment>',
                implode(', ', $drift->mixedReleaseDates),
            ));
        }

        $actionable = $drift->actionableEntries();
        if ($actionable === []) {
            $io->writeln('  All inspected packages are clean.');
            return;
        }

        foreach ($actionable as $entry) {
            $tag = match ($entry->status) {
                PackageDriftStatus::MissingFromLock,
                PackageDriftStatus::MissingFromVendor => 'comment',
                default => 'comment',
            };
            $io->writeln(sprintf(
                '  <%1$s>%2$s</%1$s>',
                $tag,
                $entry->name,
            ));
            $io->writeln(sprintf(
                '    declared:  %s',
                $entry->declared ?? '(none)',
            ));
            $io->writeln(sprintf(
                '    locked:    %s',
                $entry->locked ?? '(none)',
            ));
            $io->writeln(sprintf(
                '    installed: %s',
                $entry->installed ?? '(none)',
            ));
            $io->writeln('    status:    ' . $entry->status->value);
            $io->writeln('    action:    ' . $entry->actionHint);
        }

        $io->writeln('  <comment>Composer is not invoked by `bin/semitexa update`; run the suggested commands yourself.</comment>');
    }

    private function renderScaffoldPlan(SymfonyStyle $io, ScaffoldSyncPlan $plan): void
    {
        $io->section('Scaffold sync');

        if ($plan->hasIntegrityFailure()) {
            $io->writeln('  <error>Scaffold integrity check failed:</error>');
            foreach ($plan->integrityErrors as $error) {
                $io->writeln('    · ' . $error);
            }
            $io->writeln('  <error>Scaffold sync will be skipped and the run will abort before any DB-affecting stage.</error>');
            return;
        }

        $actionable = $plan->actionable();
        if ($actionable === []) {
            $io->writeln('  All scaffold-managed files are at the current version.');
            return;
        }

        foreach ($actionable as $entry) {
            $io->writeln(sprintf('  %s', $entry->path));
            $io->writeln('    status: ' . $entry->status->value);
            $io->writeln('    action: ' . $entry->action->value);
            if ($entry->backupPath !== null) {
                $io->writeln('    backup: ' . $entry->backupPath);
            }
            if ($entry->newFilePath !== null) {
                $io->writeln('    .new:   ' . $entry->newFilePath);
            }
            $io->writeln('    reason: ' . $entry->reason);
        }

        $conflicts = $plan->conflicts();
        if ($conflicts !== []) {
            $io->writeln(sprintf(
                '  <comment>%d locally modified file(s) will be left untouched; review the .new candidates listed above.</comment>',
                count($conflicts),
            ));
        }
    }

    private function renderScaffoldStage(SymfonyStyle $io, ScaffoldSyncReport $report): void
    {
        if ($report->integrityErrors !== []) {
            $io->writeln('  <error>Scaffold integrity check failed:</error>');
            foreach ($report->integrityErrors as $error) {
                $io->writeln('    · ' . $error);
            }
            return;
        }

        $applied = 0;
        $would = 0;
        $failed = 0;
        $noop = 0;
        $skipped = 0;
        foreach ($report->results as $result) {
            switch ($result->outcome) {
                case ScaffoldSyncOutcome::Applied:    $applied++; break;
                case ScaffoldSyncOutcome::WouldApply: $would++; break;
                case ScaffoldSyncOutcome::Failed:     $failed++; break;
                case ScaffoldSyncOutcome::NoOp:       $noop++; break;
                case ScaffoldSyncOutcome::Skipped:    $skipped++; break;
            }
        }

        if ($report->dryRun) {
            $io->writeln(sprintf('  Dry-run: would apply %d action(s), %d already current.', $would, $noop));
        } else {
            $io->writeln(sprintf(
                '  applied: %d  ·  no-op: %d  ·  failed: %d  ·  skipped: %d',
                $applied,
                $noop,
                $failed,
                $skipped,
            ));
        }

        foreach ($report->actionableResults() as $result) {
            $io->writeln(sprintf(
                '    [%s] %s  (%s → %s)',
                $result->outcome->value,
                $result->path,
                $result->action->value,
                $result->status->value,
            ));
            if ($result->backupPath !== null) {
                $io->writeln('      backup: ' . $result->backupPath);
            }
            if ($result->newFilePath !== null) {
                $io->writeln('      .new:   ' . $result->newFilePath);
            }
            if ($result->status === ScaffoldSyncStatus::LocallyModified) {
                $io->writeln('      <comment>Manual review required — live file untouched.</comment>');
            }
            if ($result->action === ScaffoldSyncAction::Replace
                && $result->outcome === ScaffoldSyncOutcome::Applied
                && $result->path === 'bin/semitexa'
            ) {
                $io->writeln('      <comment>bin/semitexa was replaced. Rerun `bin/semitexa update` if anything looks off.</comment>');
            }
        }
    }
}
