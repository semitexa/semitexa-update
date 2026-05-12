<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * uw-4 introduced an intentional duplication of the scaffold source:
 *
 *   packages/semitexa-installer/scaffold/        — authoring / Docker-install canonical
 *   packages/semitexa-update/resources/scaffold/ — runtime copy shipped to downstream vendor/
 *
 * Both must remain byte-identical, file-set-identical, and exec-bit-identical
 * for the runtime sync engine to behave correctly. Drift here would mean the
 * engine syncs stale content while the manifest still references the canonical
 * hash — silently undoing whatever uw-2.5 reconciled.
 *
 * This test is intentionally hard-coded to fail on any drift; it's the only
 * thing standing between authoring negligence and a broken downstream update.
 */
final class ScaffoldSourceParityTest extends TestCase
{
    private string $installerRoot;
    private string $updateRoot;

    protected function setUp(): void
    {
        $this->installerRoot = $this->repoRoot() . '/packages/semitexa-installer/scaffold';
        $this->updateRoot    = $this->repoRoot() . '/packages/semitexa-update/resources/scaffold';

        if (!is_dir($this->installerRoot)) {
            self::markTestSkipped('Installer scaffold directory not available in this checkout.');
        }
        if (!is_dir($this->updateRoot)) {
            self::fail(sprintf(
                'Update package is missing its scaffold copy at %s — runtime sync engine will have no source files.',
                $this->updateRoot,
            ));
        }
    }

    public function testInstallerAndUpdateScaffoldHaveIdenticalFileSets(): void
    {
        $installerFiles = $this->listFiles($this->installerRoot);
        $updateFiles    = $this->listFiles($this->updateRoot);

        $onlyInInstaller = array_diff($installerFiles, $updateFiles);
        $onlyInUpdate    = array_diff($updateFiles, $installerFiles);

        self::assertSame(
            [],
            array_values($onlyInInstaller),
            'Files exist in installer scaffold but are missing from update resources scaffold: '
            . implode(', ', $onlyInInstaller),
        );
        self::assertSame(
            [],
            array_values($onlyInUpdate),
            'Files exist in update resources scaffold but are missing from installer scaffold: '
            . implode(', ', $onlyInUpdate),
        );
    }

    public function testEveryScaffoldFileMatchesByteForByte(): void
    {
        $files = $this->listFiles($this->installerRoot);
        self::assertNotSame([], $files, 'Installer scaffold dir is empty — refusing to trivially pass this guard.');

        $mismatched = [];
        foreach ($files as $relative) {
            $installerHash = hash_file('sha256', $this->installerRoot . '/' . $relative);
            $updateHash    = hash_file('sha256', $this->updateRoot . '/' . $relative);
            if ($installerHash !== $updateHash) {
                $mismatched[$relative] = ['installer' => $installerHash, 'update' => $updateHash];
            }
        }

        self::assertSame(
            [],
            $mismatched,
            "Scaffold files diverged between installer/ and update/resources/. "
            . "Re-sync them and rebuild scaffold-manifest.json. Drift detail: "
            . json_encode($mismatched, JSON_UNESCAPED_SLASHES),
        );
    }

    public function testBinSemitexaExistsInBothCopies(): void
    {
        self::assertFileExists(
            $this->installerRoot . '/bin/semitexa',
            'Installer scaffold has lost bin/semitexa — both copies must include it.',
        );
        self::assertFileExists(
            $this->updateRoot . '/bin/semitexa',
            'Update resources scaffold has lost bin/semitexa — runtime sync would have no source for replacement.',
        );
    }

    /**
     * @return list<string>  project-relative paths under the scaffold root, sorted, slash-separated
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

    private function repoRoot(): string
    {
        // tests/Unit/Service/Scaffold → up 4 → semitexa-update, up 5 → packages, up 6 → repo root.
        return dirname(__DIR__, 6);
    }
}
