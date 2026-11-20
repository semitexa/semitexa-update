<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Installer\Application\Service\Scaffold as InstallerScaffold;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldSource;

/**
 * Until step 2 of one source for bin/semitexa, this package's manifest held the
 * history of the runtime scaffold files: every sha256 of every version a project
 * could have been installed or updated with. From that step semitexa/installer's
 * manifest holds it. A hash it forgets tells a project on that version that it
 * edited the file, and the file is never updated again (Fable's review of the
 * step 2 plan, D2).
 *
 * The fixture is that history, frozen at the switch; the installer installed
 * beside this package has to know every hash in it.
 */
final class InstallerKeepsEveryVersionUpdateShippedTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/runtime-hashes-when-the-installer-took-over.json';

    public function testTheInstallersManifestKnowsEveryVersionUpdateEverShipped(): void
    {
        $frozen = json_decode((string) file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($frozen);
        self::assertIsArray($frozen['files'] ?? null);
        self::assertCount(19, $frozen['files'], 'the fixture is the 19 runtime files at the switch; it is never regenerated');

        $installer = (new ScaffoldManifestLoader())->load(InstallerScaffold::manifestPath());
        $forgotten = [];
        $checked = 0;
        foreach ($frozen['files'] as $path => $hashes) {
            self::assertIsString($path);
            self::assertIsArray($hashes);
            $entry = $installer->entryByPath($path);
            $known = $entry === null ? [] : [$entry->currentSha256, ...$entry->previousSha256];
            // A file the installer took out on purpose keeps its versions in retired.
            $known = [...$known, ...($installer->retired[$path] ?? [])];
            foreach ($hashes as $hash) {
                self::assertIsString($hash);
                $checked++;
                if (!in_array($hash, $known, true)) {
                    $forgotten[] = "{$path} {$hash}";
                }
            }
        }

        self::assertGreaterThan(250, $checked, 'the fixture lost its history');
        self::assertSame([], $forgotten, 'semitexa/installer forgot versions projects may hold');
    }

    public function testUpdateReadsTheInstallersScaffoldFirstAndItsOwnDocsAfter(): void
    {
        $packageRoot = \dirname(__DIR__, 4);

        $sources = UpdateRunnerFactory::scaffoldSources($packageRoot);

        self::assertSame(['semitexa/installer', 'semitexa/update'], array_map(static fn (ScaffoldSource $s): string => $s->name, $sources));
        self::assertSame(InstallerScaffold::directory(), $sources[0]->root);
        self::assertSame(InstallerScaffold::manifestPath(), $sources[0]->manifestPath);
        self::assertSame($packageRoot . '/resources/scaffold', $sources[1]->root);
        self::assertSame($packageRoot . '/resources/scaffold-manifest.json', $sources[1]->manifestPath);
        self::assertFileExists($sources[0]->root . '/bin/semitexa');
        self::assertFileDoesNotExist($sources[1]->root . '/bin/semitexa', 'bin/semitexa has one source');
    }
}
