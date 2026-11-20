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
 * Authoring/release tool: regenerates the scaffold manifests a project's
 * `bin/semitexa update` reads.
 *
 * From step 2 of one source for bin/semitexa the scaffold has two sources:
 *  - semitexa/installer owns the runtime files (bin/semitexa, the Dockerfile,
 *    the Compose files). This command writes their manifest in the installer
 *    checkout beside this package, carrying the history on from that manifest:
 *    each file's earlier sha256 stay known, so a project on an older pristine
 *    version is still updated, not told it edited the file.
 *  - this package mirrors the agents' operating manual ({@see self::AGENT_DOCS})
 *    from semitexa/ultimate, where `scaffold:sync-docs` puts it, and writes its
 *    manifest with the retired list. Anything else in resources/scaffold is a
 *    file the installer owns now, and goes.
 *
 * Copying a scaffold file by hand leaves a manifest's current_sha256 stale; the
 * sync engine then refuses the source. This command is the one way the
 * manifests change.
 *
 * Only meaningful in the framework workspace, where the installer is a checkout
 * beside this package. In a project's vendor/ it refuses instead of rewriting an
 * installed package.
 */
#[AsCommand(name: 'update:scaffold:rebuild', description: "Regenerate the scaffold manifests: semitexa/installer's, and this package's for the agent docs it mirrors")]
final class UpdateScaffoldRebuildCommand extends BaseCommand
{
    /** semitexa-dev's ScaffoldSyncDocsCommand::MIRRORED_FILES minus README.md, which a project owns; checked on every rebuild. */
    public const AGENT_DOCS = ['AGENTS.md', 'AGENTS_DOCTRINE.md', 'AI_CONTEXT.md', 'AI_ENTRY.md', 'AI_REFERENCE.md', 'CLAUDE.md'];

    /** Fixture package root injected by tests via overridePackageRoot(); null in production. */
    private ?string $packageRootOverride = null;

    /**
     * Test seam: point the command at a fixture package root instead of the
     * real semitexa-update checkout. Setter rather than constructor argument —
     * container-managed commands must stay parameterless (attribute-DI rule).
     */
    public function overridePackageRoot(?string $packageRoot): void
    {
        $this->packageRootOverride = $packageRoot;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'installer-scaffold',
                null,
                InputOption::VALUE_REQUIRED,
                "Path to the installer checkout's scaffold dir (defaults to semitexa-installer beside this package)",
            )
            ->addOption(
                'agent-docs-root',
                null,
                InputOption::VALUE_REQUIRED,
                'Directory holding the agent docs (defaults to the sibling semitexa-ultimate package)',
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
            $installerManifestPath = \dirname($installerScaffold) . '/resources/scaffold-manifest.json';
            $docsRoot = $this->resolveAgentDocsRoot($input, $packageRoot);
            $this->assertDocListMatchesSyncDocs();

            $installerFiles = $this->listFiles($installerScaffold);
            if ($installerFiles === []) {
                throw new \RuntimeException("Installer scaffold has no files: {$installerScaffold}");
            }
            /** @var array<string, string> $installerSources relative path => source file */
            $installerSources = [];
            foreach ($installerFiles as $relative) {
                $installerSources[$relative] = $installerScaffold . '/' . $relative;
            }
            /** @var array<string, string> $docSources relative path => source file */
            $docSources = [];
            foreach (self::AGENT_DOCS as $doc) {
                if (isset($installerSources[$doc])) {
                    throw new \RuntimeException("{$doc} is in the installer scaffold too; the agent docs have one source, {$docsRoot}");
                }
                if (!is_file($docsRoot . '/' . $doc)) {
                    throw new \RuntimeException("Agent doc missing from {$docsRoot}: {$doc}");
                }
                $docSources[$doc] = $docsRoot . '/' . $doc;
            }

            $hasher = new ScaffoldHasher();
            $loader = new ScaffoldManifestLoader();
            $builder = new ScaffoldManifestBuilder();

            // 1. semitexa/installer's manifest, which every project's update reads
            //    from vendor/ (step 2 of one source for bin/semitexa). The history
            //    carries on from the manifest itself: the one place it lives now.
            // That manifest is the only home of the history now: never rebuild it
            // from nothing, and never let a file drop out of it without a
            // decision (Fable's review of update 1959e8d).
            if (!is_file($installerManifestPath)) {
                throw new \RuntimeException(
                    "semitexa/installer's manifest is missing: {$installerManifestPath}. It holds every version each "
                    . 'scaffold file shipped with; one built from nothing would forget them, and a project on an older '
                    . 'version would be told it edited its files.',
                );
            }
            $installerPrior = $loader->load($installerManifestPath);
            $vanished = array_values(array_diff(array_keys($installerPrior->entries), array_keys($installerSources), array_keys($installerPrior->retired)));
            if ($vanished !== []) {
                throw new \RuntimeException(
                    implode(', ', $vanished) . " left semitexa/installer's scaffold but not its manifest. List each under "
                    . '"retired" there with every sha256 it shipped with, so update removes a project\'s untouched copy, '
                    . 'or bring the file back.',
                );
            }
            $installerNew = $builder->buildFromFiles($installerSources, $installerPrior);
            $installerManifestChanged = $installerPrior->toArray()['files'] !== $installerNew->toArray()['files']
                || $installerPrior->retired !== $installerNew->retired;

            // 2. This package's own copy: the agent docs, until step 7 moves them too.
            //    Anything else in it is a file the installer owns now, and goes.
            $changed = [];
            $removed = [];
            foreach ($docSources as $relative => $src) {
                $dst = $updateScaffold . '/' . $relative;
                // Content only: the sync engine sets the executable bit from the
                // manifest's preserve_executable, so a mode difference is no drift.
                if (!is_file($dst) || $hasher->hashFile($src) !== $hasher->hashFile($dst)) {
                    $changed[] = $relative;
                    if (!$check) {
                        $this->copyFile($src, $dst);
                    }
                }
            }
            $targetFiles = is_dir($updateScaffold) ? $this->listFiles($updateScaffold) : [];
            foreach (array_diff($targetFiles, array_keys($docSources)) as $relative) {
                $removed[] = $relative;
                if (!$check) {
                    @unlink($updateScaffold . '/' . $relative);
                    $this->removeEmptyParents($updateScaffold, $relative);
                }
            }
            sort($changed);
            sort($removed);

            // Its manifest: the agent docs and the retired list, which the builder
            // carries over. The runtime entries drop out without being retired:
            // they moved, they did not go. Compared by entries, never by
            // generated_at, which the builder stamps "now".
            $prior = is_file($manifestPath) ? $loader->load($manifestPath) : null;
            $manifestSources = $check
                ? $docSources
                : array_combine(array_keys($docSources), array_map(static fn (string $r): string => $updateScaffold . '/' . $r, array_keys($docSources)));
            $new = $builder->buildFromFiles($manifestSources, $prior);
            $manifestChanged = ($prior?->toArray()['files'] ?? null) !== $new->toArray()['files'];

            if (!$check) {
                if ($installerManifestChanged) {
                    $this->atomicWrite($installerManifestPath, $this->encodeManifest($installerNew));
                }
                if ($manifestChanged) {
                    $this->atomicWrite($manifestPath, $this->encodeManifest($new));
                }
            }

            $drift = $changed !== [] || $removed !== [] || $manifestChanged || $installerManifestChanged;

            $result = [
                'status' => 'ok',
                'mode' => $check ? 'check' : 'rebuild',
                'installer_scaffold' => $installerScaffold,
                'installer_manifest_path' => $installerManifestPath,
                'installer_manifest_changed' => $installerManifestChanged,
                'agent_docs_root' => $docsRoot,
                'update_scaffold' => $updateScaffold,
                'manifest_path' => $manifestPath,
                'files_changed' => $changed,
                'files_removed' => $removed,
                'manifest_changed' => $manifestChanged,
                'in_sync' => !$drift,
            ];

            if ($asJson) {
                $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
                return ($check && $drift) ? Command::FAILURE : Command::SUCCESS;
            }

            $io = new SymfonyStyle($input, $output);
            if ($check) {
                if (!$drift) {
                    $io->success('The installer manifest, this copy of the agent docs and its manifest are in sync.');
                    return Command::SUCCESS;
                }
                $io->warning('The scaffold manifests are OUT OF SYNC with their files. Run without --check to fix.');
                $this->renderChanges($io, $changed, $removed, $manifestChanged, $installerManifestChanged);
                return Command::FAILURE;
            }

            if (!$drift) {
                $io->success('Scaffold already in sync; nothing to rebuild.');
                return Command::SUCCESS;
            }
            $io->success('Scaffold manifests regenerated.');
            $this->renderChanges($io, $changed, $removed, $manifestChanged, $installerManifestChanged);
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            if ($asJson) {
                $output->writeln(json_encode(['status' => 'failed', 'reason' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
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
    private function renderChanges(SymfonyStyle $io, array $changed, array $removed, bool $manifestChanged, bool $installerManifestChanged): void
    {
        $io->definitionList(
            ['semitexa/installer manifest' => $installerManifestChanged ? 'regenerated' : 'unchanged'],
            ['Agent docs changed' => $changed === [] ? 'none' : implode(', ', $changed)],
            ['Files removed from this copy' => $removed === [] ? 'none' : implode(', ', $removed)],
            ['This manifest' => $manifestChanged ? 'regenerated' : 'unchanged'],
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

        // The framework workspace only: packages/semitexa-installer beside this
        // package. In a project's vendor/ the installer is an installed package
        // this command would rewrite, so it is not looked for there (Fable's
        // review of the step 2 plan, D5: the refusal used to come only from the
        // missing ultimate docs, by accident of the order of the checks).
        $candidate = dirname($packageRoot) . '/semitexa-installer/scaffold';
        if (is_dir($candidate)) {
            return $candidate;
        }

        throw new \RuntimeException(
            'update:scaffold:rebuild runs in the framework workspace, where semitexa-installer is a checkout beside '
            . "this package; it rewrites that checkout's manifest, never an installed one. Not found: {$candidate}. "
            . 'Pass --installer-scaffold=<path> to a checkout to override.',
        );
    }

    /**
     * A doc scaffold:sync-docs starts mirroring into ultimate would otherwise
     * never reach a project. Only checkable where semitexa/dev is installed,
     * which is where this authoring command runs.
     */
    private function assertDocListMatchesSyncDocs(): void
    {
        $syncDocs = 'Semitexa\\Dev\\Application\\Console\\Command\\ScaffoldSyncDocsCommand';
        if (!class_exists($syncDocs) || !defined($syncDocs . '::MIRRORED_FILES')) {
            return;
        }
        $mirrored = array_values(array_diff((array) constant($syncDocs . '::MIRRORED_FILES'), ['README.md']));
        sort($mirrored);
        if ($mirrored !== self::AGENT_DOCS) {
            throw new \RuntimeException(
                'scaffold:sync-docs mirrors ' . implode(', ', $mirrored) . ' but the update scaffold carries '
                . implode(', ', self::AGENT_DOCS) . ': update UpdateScaffoldRebuildCommand::AGENT_DOCS and its policy.',
            );
        }
    }

    private function resolveAgentDocsRoot(InputInterface $input, string $packageRoot): string
    {
        $override = $input->getOption('agent-docs-root');
        if (is_string($override) && $override !== '') {
            $real = realpath($override);
            if ($real === false || !is_dir($real)) {
                throw new \RuntimeException("--agent-docs-root path is not a directory: {$override}");
            }
            return $real;
        }

        // dev monorepo: packages/semitexa-ultimate; vendor: semitexa/ultimate
        $candidates = [dirname($packageRoot) . '/semitexa-ultimate', dirname($packageRoot) . '/ultimate'];
        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/AGENTS.md')) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Could not locate the agent docs (semitexa/ultimate). Tried: ' . implode(', ', $candidates)
            . '. Pass --agent-docs-root=<path> to override.',
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
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), $rootLen));
        }
        sort($files);
        return $files;
    }

    /** The directories a removed file leaves empty, up to (not including) $root. */
    private function removeEmptyParents(string $root, string $relative): void
    {
        $dir = \dirname($relative);
        while ($dir !== '.' && $dir !== '' && $dir !== '/') {
            $absolute = $root . '/' . $dir;
            if (!is_dir($absolute) || (scandir($absolute) ?: ['.', '..', 'unreadable']) !== ['.', '..']) {
                return;
            }
            @rmdir($absolute);
            $dir = \dirname($dir);
        }
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
        // tempnam() creates 0600; without widening, a root-run container
        // leaves a manifest the host user (and phpunit) cannot read.
        // 0644, not 0664 — readable everywhere, writable by owner only.
        @chmod($tmp, 0644);
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
