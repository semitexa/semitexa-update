<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Application\Service\Changelog\PackageChangelogReader;
use Semitexa\Update\Application\Service\Packaging\Releases\Service\FrameworkDeploymentPlanner;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Semitexa\Update\Domain\Model\Changelog\ReleaseNote;
use Semitexa\Update\Domain\Enum\RunOutcome;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The change surface of this installation: which package versions changed in
 * past runs (from the run journal) and which newer versions are available
 * upstream right now (via the release sources). Release-note texts plug in
 * here once the per-package notes feed exists.
 */
#[AsCommand(name: 'update:changelog', description: 'Show applied and available Semitexa package version changes.')]
final class UpdateChangelogCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected UpdateRunnerFactory $runnerFactory;

    protected function configure(): void
    {
        $this
            ->addOption('connection', 'c', InputOption::VALUE_REQUIRED, 'Connection name for the journal', 'default')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'How many past runs to scan for applied changes', '20')
            ->addOption('no-remote', null, InputOption::VALUE_NONE, 'Skip upstream discovery (no network); show applied changes only')
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED, 'Show the CHANGELOG sections of one package (e.g. semitexa/core)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = (string) ($input->getOption('connection') ?: 'default');
        $limit = max(1, (int) $input->getOption('limit'));
        $reader = new PackageChangelogReader($this->getProjectRoot());

        $package = $input->getOption('package');
        if (is_string($package) && $package !== '') {
            return $this->renderPackageNotes($input, $output, $io, $reader, $package, $limit);
        }

        $applied = $this->appliedChanges($connection, $limit);
        $releaseNotes = $this->releaseNotesFor($reader, $applied);

        $available = [];
        $sourceWarnings = [];
        if (!$input->getOption('no-remote')) {
            $plan = (new FrameworkDeploymentPlanner())->plan($this->getProjectRoot());
            foreach ($plan->packageUpdates as $update) {
                $available[] = [
                    'package' => $update->packageName,
                    'from'    => $update->installedVersion,
                    'to'      => $update->latestVersion,
                    'source'  => $update->source,
                ];
            }
            $sourceWarnings = $plan->sourceWarnings;
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                [
                    'applied' => $applied,
                    'available' => $available,
                    'release_notes' => array_map($this->noteToArray(...), $releaseNotes),
                    'source_warnings' => $sourceWarnings,
                ],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            return Command::SUCCESS;
        }

        $io->title('Semitexa Changelog');

        $io->section('Applied here (from the run journal)');
        if ($applied === []) {
            $io->writeln('  No package version changes recorded yet.');
        } else {
            $io->table(
                ['When (UTC)', 'Package', 'From', 'To', 'Run outcome'],
                array_map(static fn (array $c): array => [
                    substr((string) $c['at'], 0, 19),
                    $c['package'],
                    $c['from'] ?? '(none)',
                    $c['to'],
                    $c['outcome'],
                ], $applied),
            );
        }

        if ($releaseNotes !== []) {
            $io->section('Release notes');
            foreach ($releaseNotes as $note) {
                $this->renderNote($io, $note);
            }
        }

        if (!$input->getOption('no-remote')) {
            $io->section('Available upstream');
            if ($available === []) {
                $io->writeln('  Everything is at the latest discovered stable version.');
            } else {
                $io->table(
                    ['Package', 'Installed', 'Latest', 'Source'],
                    array_map(static fn (array $c): array => [$c['package'], $c['from'], $c['to'], $c['source']], $available),
                );
                $io->writeln('  Apply with: bin/semitexa update');
            }

            if ($sourceWarnings !== []) {
                $io->warning(array_merge(
                    ['Release discovery was DEGRADED — "latest" above may be stale:'],
                    $sourceWarnings,
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function renderPackageNotes(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        PackageChangelogReader $reader,
        string $package,
        int $limit,
    ): int {
        $notes = $reader->latestNotes($package, $limit);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                array_map($this->noteToArray(...), $notes),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            return Command::SUCCESS;
        }

        $io->title('Changelog: ' . $package);
        if ($notes === []) {
            $io->writeln(sprintf(
                'No CHANGELOG.md found for %s (looked in vendor/ and packages/).',
                $package,
            ));
            return Command::SUCCESS;
        }

        foreach ($notes as $note) {
            $this->renderNote($io, $note);
        }
        return Command::SUCCESS;
    }

    private function renderNote(SymfonyStyle $io, ReleaseNote $note): void
    {
        $io->writeln(sprintf(
            '<info>%s %s</info>%s',
            $note->package,
            $note->version,
            $note->date !== null ? ' — ' . $note->date : '',
        ));
        $lines = explode("\n", $note->body);
        foreach (array_slice($lines, 0, 20) as $line) {
            $io->writeln('  ' . $line);
        }
        if (count($lines) > 20) {
            $io->writeln(sprintf('  … (%d more lines in CHANGELOG.md)', count($lines) - 20));
        }
        $io->newLine();
    }

    /**
     * Notes for every applied version delta that has a CHANGELOG entry.
     *
     * @param list<array{at: string, package: string, from: ?string, to: string, outcome: string}> $applied
     * @return list<ReleaseNote>
     */
    private function releaseNotesFor(PackageChangelogReader $reader, array $applied): array
    {
        $notes = [];
        $seen = [];
        foreach ($applied as $change) {
            $key = $change['package'] . '|' . ($change['from'] ?? '') . '|' . $change['to'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ($reader->notesBetween($change['package'], $change['from'], $change['to']) as $note) {
                $notes[] = $note;
            }
        }
        return $notes;
    }

    /**
     * @return array<string, mixed>
     */
    private function noteToArray(ReleaseNote $note): array
    {
        return [
            'package' => $note->package,
            'version' => $note->version,
            'date'    => $note->date,
            'body'    => $note->body,
        ];
    }

    /**
     * Flattened package deltas from recent successful runs, newest first.
     *
     * @return list<array{at: string, package: string, from: ?string, to: string, outcome: string}>
     */
    private function appliedChanges(string $connection, int $limit): array
    {
        try {
            $records = $this->runnerFactory->runJournal($connection)->findRecent($limit);
        } catch (\Throwable) {
            return [];
        }

        $changes = [];
        foreach ($records as $record) {
            if ($record->outcome === RunOutcome::Running) {
                continue;
            }
            foreach ($record->packageDeltas as $package => $delta) {
                $changes[] = [
                    'at'      => $record->completedAt ?? $record->startedAt,
                    'package' => (string) $package,
                    'from'    => isset($delta['from']) ? (string) $delta['from'] : null,
                    'to'      => (string) ($delta['to'] ?? '?'),
                    'outcome' => $record->outcome->value,
                ];
            }
        }

        return $changes;
    }
}
