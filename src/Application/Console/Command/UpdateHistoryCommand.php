<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Semitexa\Update\Domain\Model\UpdateRunRecord;
use Semitexa\Update\Exception\UpdateException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders the run journal: every update / auto-deploy run with its outcome,
 * package version deltas, and stage recap. `--id` shows one run in full.
 */
#[AsCommand(name: 'update:history', description: 'Show the update run journal: past runs, outcomes, package version changes.')]
final class UpdateHistoryCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected UpdateRunnerFactory $runnerFactory;

    protected function configure(): void
    {
        $this
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Connection name for the journal', 'default')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many runs to show', '10')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Show one run in full detail')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = (string) ($input->getOption('connection') ?: 'default');
        $limit = max(1, (int) $input->getOption('limit'));
        $id = $input->getOption('id');

        try {
            $journal = $this->runnerFactory->runJournal($connection);
            if (is_string($id) && $id !== '') {
                $record = $journal->find($id);
                if ($record === null) {
                    $io->error(sprintf('No run with id "%s" in the journal.', $id));
                    return Command::FAILURE;
                }
                return $this->renderDetail($input, $output, $io, $record);
            }
            $records = $journal->findRecent($limit);
        } catch (UpdateException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                array_map($this->recordToArray(...), $records),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            return Command::SUCCESS;
        }

        $io->title('Semitexa Update History');

        if ($records === []) {
            $io->writeln('No update runs recorded yet. The journal starts with the first `bin/semitexa update`.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                substr($record->id, 0, 12),
                $record->startedAt !== '' ? substr($record->startedAt, 0, 19) : '?',
                $record->kind,
                $record->actor ?? '—',
                $record->outcome->value . ($record->failedStage !== null ? ' @ ' . $record->failedStage : ''),
                count($record->packageDeltas),
                $record->patchesApplied,
                $record->durationMs !== null ? $record->durationMs . 'ms' : '—',
            ];
        }
        $io->table(['Run', 'Started (UTC)', 'Kind', 'Actor', 'Outcome', 'Pkg Δ', 'Patches', 'Duration'], $rows);
        $io->writeln('Details: bin/semitexa update:history --id=<run>');

        return Command::SUCCESS;
    }

    private function renderDetail(InputInterface $input, OutputInterface $output, SymfonyStyle $io, UpdateRunRecord $record): int
    {
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($this->recordToArray($record), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }

        $io->title('Update Run ' . $record->id);
        $io->definitionList(
            ['Kind' => $record->kind],
            ['Actor' => $record->actor ?? '—'],
            ['Updater version' => $record->updaterVersion ?? '—'],
            ['Outcome' => $record->outcome->value],
            ['Failed stage' => $record->failedStage ?? '—'],
            ['Started (UTC)' => $record->startedAt],
            ['Completed (UTC)' => $record->completedAt ?? '—'],
            ['Duration' => $record->durationMs !== null ? $record->durationMs . 'ms' : '—'],
            ['Patches applied' => (string) $record->patchesApplied],
        );

        if ($record->packageDeltas !== []) {
            $rows = [];
            foreach ($record->packageDeltas as $package => $delta) {
                $rows[] = [$package, $delta['from'] ?? '(none)', $delta['to'] ?? '?'];
            }
            $io->section('Package changes');
            $io->table(['Package', 'From', 'To'], $rows);
        }

        if ($record->stages !== []) {
            $io->section('Stages');
            foreach ($record->stages as $stage) {
                $name = (string) ($stage['name'] ?? '?');
                $success = (bool) ($stage['success'] ?? false);
                $extra = array_diff_key($stage, ['name' => 1, 'success' => 1]);
                $io->writeln(sprintf(
                    '  [%s] %s%s',
                    $success ? 'ok' : 'FAIL',
                    $name,
                    $extra !== [] ? '  ' . json_encode($extra, JSON_UNESCAPED_SLASHES) : '',
                ));
            }
        }

        if ($record->error !== null) {
            $io->warning($record->error);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordToArray(UpdateRunRecord $record): array
    {
        return [
            'id'              => $record->id,
            'kind'            => $record->kind,
            'actor'           => $record->actor,
            'updater_version' => $record->updaterVersion,
            'outcome'         => $record->outcome->value,
            'failed_stage'    => $record->failedStage,
            'stages'          => $record->stages,
            'package_deltas'  => $record->packageDeltas,
            'patches_applied' => $record->patchesApplied,
            'error'           => $record->error,
            'started_at'      => $record->startedAt,
            'completed_at'    => $record->completedAt,
            'duration_ms'     => $record->durationMs,
        ];
    }
}
