<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Console\Command\UpdateScaffoldRebuildCommand;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Locks the contract of `update:scaffold:rebuild` from step 2 of one source for
 * bin/semitexa: it writes semitexa/installer's manifest in the checkout beside
 * this package, carrying each file's history on from that manifest, and keeps
 * this package's copy to the agent docs from semitexa/ultimate, with their
 * manifest and the retired list. It is the one way a manifest changes, so a
 * scaffold file copied by hand cannot leave one stale.
 *
 * The fixture is the framework workspace's layout: semitexa-update,
 * semitexa-installer and semitexa-ultimate side by side.
 */
final class UpdateScaffoldRebuildCommandTest extends TestCase
{
    private const BIN_OLD = "#!/bin/sh\necho old\n";
    private const BIN_NEW = "#!/bin/sh\necho new\n";

    private string $base = '';
    private string $packageRoot = '';
    private string $updateScaffold = '';
    private string $manifestPath = '';
    private string $installerScaffold = '';
    private string $installerManifestPath = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/semitexa-scaffold-rebuild-' . bin2hex(random_bytes(8));
        $this->packageRoot = $this->base . '/semitexa-update';
        $this->updateScaffold = $this->packageRoot . '/resources/scaffold';
        $this->manifestPath = $this->packageRoot . '/resources/scaffold-manifest.json';
        $this->installerScaffold = $this->base . '/semitexa-installer/scaffold';
        $this->installerManifestPath = $this->base . '/semitexa-installer/resources/scaffold-manifest.json';

        // The installer's manifest describes the OLD bin/semitexa; its checkout
        // now carries the NEW one, as after an edit and before the rebuild.
        mkdir($this->installerScaffold . '/bin', 0777, true);
        mkdir(\dirname($this->installerManifestPath), 0777, true);
        file_put_contents($this->installerScaffold . '/bin/semitexa', self::BIN_OLD);
        $this->writeManifest($this->installerManifestPath, (new ScaffoldManifestBuilder())->build($this->installerScaffold, null, '2026-01-01T00:00:00+00:00')->toArray());
        file_put_contents($this->installerScaffold . '/bin/semitexa', self::BIN_NEW);

        // The agent docs' source: semitexa/ultimate beside this package.
        mkdir($this->base . '/semitexa-ultimate', 0777, true);
        foreach (UpdateScaffoldRebuildCommand::AGENT_DOCS as $doc) {
            file_put_contents($this->base . '/semitexa-ultimate/' . $doc, "# {$doc}\n");
        }

        // This package's copy as it was before the switch: a runtime file beside
        // the docs, both in its manifest, and a retired file.
        mkdir($this->updateScaffold . '/bin', 0777, true);
        file_put_contents($this->updateScaffold . '/bin/semitexa', self::BIN_OLD);
        foreach (UpdateScaffoldRebuildCommand::AGENT_DOCS as $doc) {
            file_put_contents($this->updateScaffold . '/' . $doc, "# {$doc}, older\n");
        }
        $own = (new ScaffoldManifestBuilder())->build($this->updateScaffold, null, '2026-01-01T00:00:00+00:00')->toArray();
        $own['retired'] = [['path' => 'docker-compose.gone.yml', 'shipped_sha256' => [str_repeat('c', 64)]]];
        $this->writeManifest($this->manifestPath, $own);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function testTheInstallersManifestFollowsItsFilesAndKeepsTheirHistory(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));

        $entry = $this->entry($this->installerManifestPath, 'bin/semitexa');
        self::assertSame(hash('sha256', self::BIN_NEW), $entry['current_sha256']);
        // A project still on the old pristine file is updated, not told it edited it.
        self::assertContains(hash('sha256', self::BIN_OLD), $entry['previous_sha256']);
    }

    public function testThisPackagesCopyKeepsOnlyTheAgentDocs(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));

        $left = array_values(array_diff(scandir($this->updateScaffold) ?: [], ['.', '..']));
        sort($left);
        $docs = UpdateScaffoldRebuildCommand::AGENT_DOCS;
        sort($docs);
        self::assertSame($docs, $left, 'the runtime files are the installer\'s now');

        $manifest = $this->manifest($this->manifestPath);
        self::assertSame($docs, array_column($manifest['files'], 'path'));
        // The runtime entry moved; it did not go, so it is not retired either.
        self::assertSame(['docker-compose.gone.yml'], array_column($manifest['retired'] ?? [], 'path'));
    }

    public function testAgentDocsComeFromUltimateIntoTheCopyAndTheManifest(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));

        foreach (UpdateScaffoldRebuildCommand::AGENT_DOCS as $doc) {
            self::assertFileEquals($this->base . '/semitexa-ultimate/' . $doc, $this->updateScaffold . '/' . $doc);
            self::assertSame(hash_file('sha256', $this->base . '/semitexa-ultimate/' . $doc), $this->entry($this->manifestPath, $doc)['current_sha256']);
            self::assertSame('agent_docs', $this->entry($this->manifestPath, $doc)['category'] ?? null);
        }
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--check' => true]));

        file_put_contents($this->base . '/semitexa-ultimate/AGENTS.md', "# AGENTS.md, with directive 11\n");
        self::assertSame(Command::FAILURE, $this->tester()->execute(['--check' => true]), 'a newer manual is drift');
        $before = $this->entry($this->manifestPath, 'AGENTS.md')['current_sha256'];
        $this->tester()->execute([]);
        self::assertContains($before, $this->entry($this->manifestPath, 'AGENTS.md')['previous_sha256'], 'a project still on the old manual stays eligible');
    }

    public function testCheckReportsInSyncAfterRebuildAndWritesNothing(): void
    {
        $this->tester()->execute([]);
        $installerAfter = (string) file_get_contents($this->installerManifestPath);
        $ownAfter = (string) file_get_contents($this->manifestPath);

        $tester = $this->tester();
        $exit = $tester->execute(['--check' => true, '--json' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('"in_sync": true', $tester->getDisplay());
        self::assertSame($installerAfter, (string) file_get_contents($this->installerManifestPath), '--check must not write the installer manifest.');
        self::assertSame($ownAfter, (string) file_get_contents($this->manifestPath), '--check must not write this manifest.');
    }

    public function testCheckFailsAndWritesNothingWhenEitherManifestIsStale(): void
    {
        $installerBefore = (string) file_get_contents($this->installerManifestPath);
        $ownBefore = (string) file_get_contents($this->manifestPath);

        $tester = $this->tester();
        $exit = $tester->execute(['--check' => true, '--json' => true]);

        self::assertSame(Command::FAILURE, $exit);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertFalse($result['in_sync']);
        self::assertTrue($result['installer_manifest_changed'], 'the installer file changed after its manifest');
        self::assertSame($installerBefore, (string) file_get_contents($this->installerManifestPath));
        self::assertSame($ownBefore, (string) file_get_contents($this->manifestPath));
        self::assertFileExists($this->updateScaffold . '/bin/semitexa', '--check removes nothing');
    }

    /**
     * Fable's review of the step 2 plan, D5: in a project, vendor/semitexa/installer
     * is an installed package. The command used to accept it, and refused only
     * because semitexa/ultimate's docs were missing.
     */
    public function testInAVendorInstallItRefusesAndRewritesNothing(): void
    {
        $vendor = $this->base . '/project/vendor/semitexa';
        mkdir($vendor . '/installer/scaffold/bin', 0777, true);
        mkdir($vendor . '/installer/resources', 0777, true);
        mkdir($vendor . '/ultimate', 0777, true);
        mkdir($vendor . '/update/resources/scaffold', 0777, true);
        file_put_contents($vendor . '/installer/scaffold/bin/semitexa', self::BIN_NEW);
        file_put_contents($vendor . '/installer/resources/scaffold-manifest.json', '{"installed": true}');
        foreach (UpdateScaffoldRebuildCommand::AGENT_DOCS as $doc) {
            file_put_contents($vendor . '/ultimate/' . $doc, "# {$doc}\n");
        }

        $tester = $this->tester($vendor . '/update');
        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('runs in the framework workspace', $tester->getDisplay());
        self::assertSame('{"installed": true}', file_get_contents($vendor . '/installer/resources/scaffold-manifest.json'));
    }

    public function testAMissingInstallerManifestIsRefusedNotRebuiltFromNothing(): void
    {
        unlink($this->installerManifestPath);

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute([]));

        self::assertStringContainsString("semitexa/installer's manifest is missing", (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertFileDoesNotExist($this->installerManifestPath, 'no manifest without its history');
    }

    public function testAFileThatLeftTheInstallerScaffoldMustBeRetiredFirst(): void
    {
        // A second file in the manifest, then gone from the scaffold.
        file_put_contents($this->installerScaffold . '/docker-compose.yml', "services: {}\n");
        $manifest = (new ScaffoldManifestBuilder())->build($this->installerScaffold, null, '2026-01-01T00:00:00+00:00')->toArray();
        $this->writeManifest($this->installerManifestPath, $manifest);
        unlink($this->installerScaffold . '/docker-compose.yml');

        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute([]));
        // SymfonyStyle wraps the error at the terminal width.
        self::assertStringContainsString("docker-compose.yml left semitexa/installer's scaffold but not its manifest", (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        // Retired with what it shipped with, it may go.
        $manifest['retired'] = [['path' => 'docker-compose.yml', 'shipped_sha256' => [hash('sha256', "services: {}\n")]]];
        $kept = [];
        foreach (is_array($manifest['files'] ?? null) ? $manifest['files'] : [] as $entry) {
            if (is_array($entry) && ($entry['path'] ?? null) !== 'docker-compose.yml') {
                $kept[] = $entry;
            }
        }
        $manifest['files'] = $kept;
        $this->writeManifest($this->installerManifestPath, $manifest);
        self::assertSame(Command::SUCCESS, $this->tester()->execute([]));
        self::assertSame(['docker-compose.yml'], array_column($this->manifest($this->installerManifestPath)['retired'] ?? [], 'path'));
    }

    public function testFailsClearlyWhenTheInstallerScaffoldGivenIsMissing(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--installer-scaffold' => $this->base . '/does-not-exist']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('not a directory', $tester->getDisplay());
    }

    public function testAMissingAgentDocOrASecondSourceFailsTheRebuild(): void
    {
        unlink($this->base . '/semitexa-ultimate/AI_REFERENCE.md');
        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('AI_REFERENCE.md', $tester->getDisplay());

        file_put_contents($this->base . '/semitexa-ultimate/AI_REFERENCE.md', "# back\n");
        file_put_contents($this->installerScaffold . '/CLAUDE.md', "# a stray copy\n");
        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('installer scaffold too', $tester->getDisplay());
    }

    private function tester(?string $packageRoot = null): CommandTester
    {
        $cmd = new UpdateScaffoldRebuildCommand();
        $cmd->overridePackageRoot($packageRoot ?? $this->packageRoot);
        // The framework's command discovery sets the name from the Semitexa
        // #[AsCommand] attribute at runtime; the bare Symfony tester needs it set.
        $cmd->setName('update:scaffold:rebuild');

        return new CommandTester($cmd);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(string $path, array $manifest): void
    {
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * @return array{files: list<array<string, mixed>>, retired?: list<array<string, mixed>>}
     */
    private function manifest(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);
        self::assertIsArray($data['files'] ?? null);
        /** @var array{files: list<array<string, mixed>>, retired?: list<array<string, mixed>>} $data */
        return $data;
    }

    /**
     * @return array{path: string, current_sha256: string, previous_sha256: list<string>, category?: string}
     */
    private function entry(string $manifestPath, string $path): array
    {
        foreach ($this->manifest($manifestPath)['files'] as $entry) {
            if (($entry['path'] ?? null) === $path) {
                /** @var array{path: string, current_sha256: string, previous_sha256: list<string>, category?: string} $entry */
                return $entry;
            }
        }
        self::fail("{$manifestPath} has no entry for {$path}");
    }
}
