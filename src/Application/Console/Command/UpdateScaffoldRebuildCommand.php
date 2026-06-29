<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestBuilder;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Authoring/release tool. Mirrors the canonical installer scaffold
 * (packages/semitexa-installer/scaffold — the SSoT) into this package's
 * downstream runtime copy (resources/scaffold) AND regenerates
 * resources/scaffold-manifest.json in one atomic step.
 *
 * This closes the footgun where copying a scaffold file by hand leaves the
 * manifest's current_sha256 stale (the runtime sync engine then refuses to
 * replace, or replaces against a wrong hash). ScaffoldSourceParityTest guards
 * the file copy; the manifest rebuild here keeps current_sha256 aligned and
 * prepends the prior hash into previous_sha256 so downstream projects on the
 * previous pristine version stay eligible for safe auto-update.
 *
 * Only meaningful in a checkout where the installer package sits beside this
 * one (the dev monorepo). In a downstream vendor/ install there is nothing to
 * rebuild from; the command fails clearly instead of guessing.
 */
#[AsCommand(name: 'update:scaffold:rebuild', description: 'Mirror the installer scaffold into this package and regenerate scaffold-manifest.json')]
final class UpdateScaffoldRebuildCommand extends BaseCommand
{
    /**
     * @param string|null $packageRootOverride Test seam only. Left null in
     *   production — the framework instantiates commands via `new` when the
     *   constructor has no required parameters, so this stays optional.
     */
    public function __construct(private readonly ?string $packageRootOverride = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'installer-scaffold',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the canonical installer scaffold dir (defaults to the sibling semitexa-installer package)',
            )
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Dry run: report drift and exit non-zero if the copy or manifest is out of sync. Writes nothing.',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asJson = (bool) $input->getOption('json');
        $check = (bool) $input->getOption('check');

        $packageRoot = $this->packageRoot();
        $updateScaffold = $packageRoot . '/resources/scaffold';
        $manifestPath = $packageRoot . '/resources/scaffold-manifest.json';

        try {
            $installerScaffold = $this->resolveInstallerScaffold($input, $packageRoot);

            $sourceFiles = $this->listFiles($installerScaffold);
            if ($sourceFiles === []) {
                throw new \RuntimeException("Installer scaffold has no files: {$installerScaffold}");
            }
            $targetFiles = is_dir($updateScaffold) ? $this->listFiles($updateScaffold) : [];

            $hasher = new ScaffoldHasher();

            $changed = [];   // relative paths copied (content or mode drift)
            $removed = [];   // relative paths deleted from target (absent from source)

            // Content-only drift, matching ScaffoldSourceParityTest (which hashes
            // bytes, not mode). The runtime sync engine sets the executable bit at
            // materialization via the manifest's preserve_executable policy, so a
            // mode difference between the SSoT and this template copy is harmless
            // and must not be reported as drift.
            foreach ($sourceFiles as $relative) {
                $src = $installerScaffold . '/' . $relative;
                $dst = $updateScaffold . '/' . $relative;
                if (!is_file($dst) || $hasher->hashFile($src) !== $hasher->hashFile($dst)) {
                    $changed[] = $relative;
                    if (!$check) {
                        $this->copyFile($src, $dst);
                    }
                }
            }

            foreach (array_diff($targetFiles, $sourceFiles) as $relative) {
                $removed[] = $relative;
                if (!$check) {
                    @unlink($updateScaffold . '/' . $relative);
                }
            }
            sort($changed);
            sort($removed);

            // Manifest: build from the (now-synced) update scaffold, growing the
            // previous_sha256 registry off the prior manifest. In --check we build
            // from the installer scaffold (the post-sync target state) so we can
            // tell whether the committed manifest would change.
            //
            // Compare ENTRIES only, never generated_at — the builder stamps "now",
            // so comparing the full JSON would report perpetual drift and rewrite
            // the file on every run. A rewrite (with a fresh timestamp) happens
            // only when a file's hash registry actually changed.
            $prior = is_file($manifestPath) ? (new ScaffoldManifestLoader())->load($manifestPath) : null;
            $manifestSource = $check ? $installerScaffold : $updateScaffold;
            $new = (new ScaffoldManifestBuilder())->build($manifestSource, $prior);
            $priorFiles = $prior?->toArray()['files'] ?? null;
            $manifestChanged = $priorFiles !== $new->toArray()['files'];

            if (!$check && $manifestChanged) {
                $this->atomicWrite($manifestPath, $this->encodeManifest($new));
            }

            $drift = $changed !== [] || $removed !== [] || $manifestChanged;

            $result = [
                'status' => 'ok',
                'mode' => $check ? 'check' : 'rebuild',
                'installer_scaffold' => $installerScaffold,
                'update_scaffold' => $updateScaffold,
                'manifest_path' => $manifestPath,
                'files_changed' => $changed,
                'files_removed' => $removed,
                'manifest_changed' => $manifestChanged,
                'in_sync' => !$drift,
            ];

            if ($asJson) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return ($check && $drift) ? Command::FAILURE : Command::SUCCESS;
            }

            $io = new SymfonyStyle($input, $output);
            if ($check) {
                if (!$drift) {
                    $io->success('Scaffold copy and manifest are in sync with the installer SSoT.');
                    return Command::SUCCESS;
                }
                $io->warning('Scaffold is OUT OF SYNC with the installer SSoT. Run without --check to fix.');
                $this->renderChanges($io, $changed, $removed, $manifestChanged);
                return Command::FAILURE;
            }

            if (!$drift) {
                $io->success('Scaffold already in sync; nothing to rebuild.');
                return Command::SUCCESS;
            }
            $io->success('Scaffold mirrored and scaffold-manifest.json regenerated.');
            $this->renderChanges($io, $changed, $removed, $manifestChanged);
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($asJson) {
                $output->writeln(json_encode(['status' => 'failed', 'reason' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return Command::FAILURE;
            }
            (new SymfonyStyle($input, $output))->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * @param list<string> $changed
     * @param list<string> $removed
     */
    private function renderChanges(SymfonyStyle $io, array $changed, array $removed, bool $manifestChanged): void
    {
        $io->definitionList(
            ['Files changed' => $changed === [] ? 'none' : implode(', ', $changed)],
            ['Files removed' => $removed === [] ? 'none' : implode(', ', $removed)],
            ['Manifest' => $manifestChanged ? 'regenerated' : 'unchanged'],
        );
    }

    private function resolveInstallerScaffold(InputInterface $input, string $packageRoot): string
    {
        $override = $input->getOption('installer-scaffold');
        if (is_string($override) && $override !== '') {
            $real = realpath($override);
            if ($real === false || !is_dir($real)) {
                throw new \RuntimeException("--installer-scaffold path is not a directory: {$override}");
            }
            return $real;
        }

        // dev monorepo: packages/semitexa-installer/scaffold; vendor: semitexa/installer/scaffold
        $candidates = [
            dirname($packageRoot) . '/semitexa-installer/scaffold',
            dirname($packageRoot) . '/installer/scaffold',
        ];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Could not locate the installer scaffold (SSoT). This authoring command needs the '
            . 'semitexa-installer package beside this one. Tried: ' . implode(', ', $candidates)
            . '. Pass --installer-scaffold=<path> to override.',
        );
    }

    /**
     * @return list<string> project-relative paths under $root, sorted, slash-separated
     */
    private function listFiles(string $root): array
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        $rootLen = strlen($root) + 1;
        $files = [];
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), $rootLen));
        }
        sort($files);
        return $files;
    }

    private function copyFile(string $src, string $dst): void
    {
        $dir = dirname($dst);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
        if (!copy($src, $dst)) {
            throw new \RuntimeException("Failed to copy {$src} -> {$dst}");
        }
        // Preserve the source mode bits (the executable scaffold files rely on +x).
        @chmod($dst, fileperms($src) & 0777);
    }

    private function encodeManifest(ScaffoldManifest $manifest): string
    {
        $json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $json . "\n";
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read: {$path}");
        }
        return $contents;
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $dir = dirname($path);
        $tmp = tempnam($dir, basename($path) . '.tmp.');
        if ($tmp === false) {
            throw new \RuntimeException("Failed to create temp file for: {$path}");
        }
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to write: {$path}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to finalize write: {$path}");
        }
    }

    /**
     * On-disk root of the semitexa/update package itself. Works both in the dev
     * monorepo (packages/semitexa-update) and a downstream vendor/ install.
     */
    private function packageRoot(): string
    {
        return $this->packageRootOverride ?? dirname(__DIR__, 4);
    }
}
