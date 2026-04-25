<?php

declare(strict_types=1);

namespace Semitexa\Update\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Update\Packaging\Releases\Support\ReleaseComposerManifestBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'update:packages:materialize', description: 'Generate a production-friendly composer manifest with exact Semitexa package versions and VCS repositories')]
final class UpdatePackagesMaterializeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output file path relative to the project root', 'composer.release.json')
            ->addOption('write-root', null, InputOption::VALUE_NONE, 'Replace composer.json with the release manifest and keep a dev-workspace backup')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->getProjectRoot();
        $builder = new ReleaseComposerManifestBuilder();

        try {
            $manifest = $builder->build($projectRoot);
            $writtenPath = $this->resolveOutputPath($projectRoot, (string) $input->getOption('output'));
            $backupPath = null;

            if ($input->getOption('write-root')) {
                $backupPath = $projectRoot . '/composer.dev-workspace.json';
                $this->writeJson($backupPath, $this->readExistingComposer($projectRoot . '/composer.json'));
                $writtenPath = $projectRoot . '/composer.json';
            }

            $this->writeJson($writtenPath, $manifest);

            $result = [
                'status' => 'ok',
                'project_root' => $projectRoot,
                'output_path' => $writtenPath,
                'backup_path' => $backupPath,
                'write_root' => (bool) $input->getOption('write-root'),
            ];

            if ($input->getOption('json')) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return Command::SUCCESS;
            }

            $io = new SymfonyStyle($input, $output);
            $io->success('Release composer manifest generated.');
            $io->definitionList(
                ['Project root' => $projectRoot],
                ['Output path' => $writtenPath],
                ['Backup path' => $backupPath ?? 'not written'],
            );

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($input->getOption('json')) {
                $output->writeln(json_encode([
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return Command::FAILURE;
            }

            $io = new SymfonyStyle($input, $output);
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readExistingComposer(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Failed to read composer.json.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Failed to decode composer.json.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException(sprintf('Output directory does not exist: %s', $directory));
        }

        $tempPath = tempnam($directory, basename($path) . '.tmp.');
        if ($tempPath === false) {
            throw new \RuntimeException(sprintf('Failed to create temporary file for: %s', $path));
        }

        if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
            @unlink($tempPath);
            throw new \RuntimeException(sprintf('Failed to write JSON file: %s', $path));
        }

        if (!rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new \RuntimeException(sprintf('Failed to finalize JSON file write: %s', $path));
        }
    }

    private function resolveOutputPath(string $projectRoot, string $output): string
    {
        $relative = ltrim(str_replace('\\', '/', trim($output)), '/');
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new \RuntimeException('Invalid output path.');
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException('Output path must stay inside project root.');
            }
        }

        return $projectRoot . '/' . $relative;
    }
}
