<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Console\Command\UpdateScaffoldRebuildCommand;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Locks the contract of `update:scaffold:rebuild`: it must mirror the installer
 * SSoT into this package's resources/scaffold AND keep scaffold-manifest.json
 * aligned (growing previous_sha256), in one step — the atomic operation that
 * replaces the error-prone manual "copy file, forget to rebuild manifest" dance
 * that left ScaffoldSourceParityTest red.
 */
final class UpdateScaffoldRebuildCommandTest extends TestCase
{
    private string $base;
    private string $packageRoot;
    private string $updateScaffold;
    private string $manifestPath;
    private string $installerScaffold;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/semitexa-scaffold-rebuild-' . bin2hex(random_bytes(8));
        $this->packageRoot = $this->base . '/package';
        $this->updateScaffold = $this->packageRoot . '/resources/scaffold';
        $this->manifestPath = $this->packageRoot . '/resources/scaffold-manifest.json';
        $this->installerScaffold = $this->base . '/installer/scaffold';

        mkdir($this->updateScaffold . '/bin', 0777, true);
        mkdir($this->installerScaffold . '/bin', 0777, true);

        // Update copy is the OLD pristine version; installer SSoT carries NEW content.
        file_put_contents($this->updateScaffold . '/bin/semitexa', "#!/bin/sh\necho old\n");
        file_put_contents($this->installerScaffold . '/bin/semitexa', "#!/bin/sh\necho new\n");

        // Seed the committed manifest for the OLD content.
        $manifest = (new ScaffoldManifestBuilder())->build($this->updateScaffold, null, '2026-01-01T00:00:00+00:00');
        file_put_contents(
            $this->manifestPath,
            json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    protected function tearDown(): void
    {
        $this->rrm($this->base);
    }

    public function testRebuildMirrorsInstallerAndRegeneratesManifest(): void
    {
        $oldHash = hash_file('sha256', $this->updateScaffold . '/bin/semitexa');
        $newHash = hash_file('sha256', $this->installerScaffold . '/bin/semitexa');
        self::assertNotSame($oldHash, $newHash);

        $exit = $this->tester()->execute([
            '--installer-scaffold' => $this->installerScaffold,
        ]);

        self::assertSame(Command::SUCCESS, $exit);

        // File mirrored.
        self::assertSame(
            $newHash,
            hash_file('sha256', $this->updateScaffold . '/bin/semitexa'),
            'update scaffold bin/semitexa must now match the installer SSoT byte-for-byte.',
        );

        // Manifest re-pointed to the new hash, with the old hash preserved for
        // downstream auto-update eligibility.
        $entry = $this->manifestEntry('bin/semitexa');
        self::assertSame($newHash, $entry['current_sha256']);
        self::assertContains($oldHash, $entry['previous_sha256']);
    }

    public function testCheckReportsInSyncAfterRebuildAndWritesNothing(): void
    {
        $this->tester()->execute(['--installer-scaffold' => $this->installerScaffold]);
        $manifestAfterRebuild = (string) file_get_contents($this->manifestPath);

        $tester = $this->tester();
        $exit = $tester->execute([
            '--installer-scaffold' => $this->installerScaffold,
            '--check' => true,
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('"in_sync": true', $tester->getDisplay());
        self::assertSame(
            $manifestAfterRebuild,
            (string) file_get_contents($this->manifestPath),
            '--check must not mutate the manifest.',
        );
    }

    public function testCheckFailsAndWritesNothingWhenDrifted(): void
    {
        $manifestBefore = (string) file_get_contents($this->manifestPath);
        $fileBefore = (string) file_get_contents($this->updateScaffold . '/bin/semitexa');

        $tester = $this->tester();
        $exit = $tester->execute([
            '--installer-scaffold' => $this->installerScaffold,
            '--check' => true,
            '--json' => true,
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('"in_sync": false', $tester->getDisplay());

        // Nothing on disk changed.
        self::assertSame($manifestBefore, (string) file_get_contents($this->manifestPath));
        self::assertSame($fileBefore, (string) file_get_contents($this->updateScaffold . '/bin/semitexa'));
    }

    public function testFailsClearlyWhenInstallerScaffoldMissing(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([
            '--installer-scaffold' => $this->base . '/does-not-exist',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('not a directory', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $cmd = new UpdateScaffoldRebuildCommand();
        $cmd->overridePackageRoot($this->packageRoot);
        // The framework's command discovery sets the name from the Semitexa
        // #[AsCommand] attribute at runtime; the bare Symfony tester needs it set.
        $cmd->setName('update:scaffold:rebuild');

        return new CommandTester($cmd);
    }

    /**
     * @return array{path: string, current_sha256: string, previous_sha256: list<string>}
     */
    private function manifestEntry(string $path): array
    {
        $data = json_decode((string) file_get_contents($this->manifestPath), true);
        self::assertIsArray($data);
        foreach ($data['files'] as $entry) {
            if ($entry['path'] === $path) {
                return $entry;
            }
        }
        self::fail("Manifest has no entry for {$path}");
    }

    private function rrm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
