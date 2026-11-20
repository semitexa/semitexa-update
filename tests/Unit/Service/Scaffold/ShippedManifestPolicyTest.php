<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestPolicy;
use Semitexa\Update\Application\Service\UpdateRunnerFactory;
use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
use Semitexa\Update\Domain\Enum\ScaffoldFileHashMatch;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;

/**
 * Locks in the policy + content invariants of the SHIPPED manifests, as
 * `bin/semitexa update` reads them (UpdateRunnerFactory::scaffoldSources()):
 * semitexa/installer's for the runtime files, this package's for the agent
 * docs (step 2 of one source for bin/semitexa). Builder tests
 * (uw-2) verify the mechanism; this file pins the actual product so a
 * regeneration that drops `bin/semitexa` from critical or loses the recorded
 * prior hash from uw-2.5 immediately fails CI.
 */
final class ShippedManifestPolicyTest extends TestCase
{
    /**
     * Old scaffold `bin/semitexa` hash captured in uw-2.5 when the canonical
     * scaffold was updated from the 77 KB version to the 102 KB version.
     * Downstream projects (incl. the one that triggered this whole epic)
     * still carry the 77 KB version, so the sync engine must continue to
     * recognise this hash as KnownPrevious.
     */
    private const KNOWN_PRIOR_BIN_HASH = '8b06fad28f3002bf7ac66b19444edb951555b6c6e3ee646daccb04dc63e9dfe7';

    /** Every source's entries in one: the paths a project's update manages. */
    private ScaffoldManifest $manifest;

    /** @var list<array{string, ScaffoldManifest}> each source's root and its manifest */
    private array $sources = [];

    protected function setUp(): void
    {
        $loader = new ScaffoldManifestLoader();
        $entries = [];
        foreach (UpdateRunnerFactory::scaffoldSources(dirname(__DIR__, 4)) as $source) {
            $manifest = $loader->load($source->manifestPath);
            $this->sources[] = [$source->root, $manifest];
            self::assertSame([], array_intersect_key($entries, $manifest->entries), "{$source->name} ships a path another source ships too");
            $entries += $manifest->entries;
        }
        self::assertCount(2, $this->sources);
        $this->manifest = new ScaffoldManifest(ScaffoldManifest::SCHEMA_VERSION, $this->sources[0][1]->generatedAt, $entries);
    }

    public function testShippedManifestSchemaVersionIsV1(): void
    {
        foreach ($this->sources as [$root, $manifest]) {
            self::assertSame(ScaffoldManifest::SCHEMA_VERSION, $manifest->schemaVersion, $root);
        }
    }

    public function testBinSemitexaIsPresentCriticalAndExecutable(): void
    {
        $bin = $this->manifest->entryByPath('bin/semitexa');

        self::assertNotNull($bin, 'bin/semitexa must always be in the shipped manifest.');
        self::assertTrue($bin->critical, 'bin/semitexa must remain critical=true.');
        self::assertTrue($bin->preserveExecutable, 'bin/semitexa must remain preserve_executable=true.');
        self::assertSame(ScaffoldFileCategory::Executable, $bin->category);
        self::assertTrue($bin->autoUpdate, 'bin/semitexa must remain auto_update=true for KnownPrevious replacement.');
    }

    public function testBinSemitexaPreviousHashRegistryStillRecognisesKnownPriorVersion(): void
    {
        $bin = $this->manifest->entryByPath('bin/semitexa');
        self::assertNotNull($bin);

        self::assertContains(
            self::KNOWN_PRIOR_BIN_HASH,
            $bin->previousSha256,
            'The pre-uw-2.5 bin/semitexa hash must remain in previous_sha256 — without it, '
            . 'downstream projects with the 77 KB version would classify as LocallyModified '
            . 'and never get the port-broker fix.',
        );

        // Same hash also classifies correctly through the entry's classifier method.
        self::assertSame(
            ScaffoldFileHashMatch::KnownPrevious,
            $bin->classifyHash(self::KNOWN_PRIOR_BIN_HASH),
        );
    }

    public function testEveryManifestEntryHasAPolicyDefault(): void
    {
        $policy = (new ScaffoldManifestPolicy())->defaults();
        foreach (array_keys($this->manifest->entries) as $path) {
            self::assertArrayHasKey(
                $path,
                $policy,
                "Manifest contains a path ({$path}) with no entry in ScaffoldManifestPolicy::defaults().",
            );
        }
    }

    public function testEveryPolicyDefaultHasAManifestEntry(): void
    {
        $policy = (new ScaffoldManifestPolicy())->defaults();
        $entryPaths = array_keys($this->manifest->entries);

        foreach (array_keys($policy) as $path) {
            self::assertContains(
                $path,
                $entryPaths,
                "Policy declares {$path} but the shipped manifest has no entry for it — "
                . "rebuild the manifest after adding files to the scaffold.",
            );
        }
    }

    public function testEachShippedManifestMatchesItsScaffoldByteForByte(): void
    {
        // verify:accept-test-change ScaffoldSourceParityTest compared update's copy of the runtime files with the installer's; since step 2 of one source update keeps no copy and reads the installer's files, which this test checks against their manifest
        $hasher = new ScaffoldHasher();
        $stale = [];
        foreach ($this->sources as [$scaffoldRoot, $manifest]) {
            foreach ($manifest->entries as $entry) {
                $absolute = $scaffoldRoot . '/' . $entry->path;
                self::assertFileExists(
                    $absolute,
                    "Manifest references {$entry->path} but the file is missing from {$scaffoldRoot}.",
                );
                $actual = $hasher->hashFile($absolute);
                if ($actual !== $entry->currentSha256) {
                    $stale[$entry->path] = ['manifest' => $entry->currentSha256, 'scaffold' => $actual];
                }
            }
        }

        self::assertSame(
            [],
            $stale,
            "Shipped manifest current_sha256 disagrees with the runtime scaffold dir for: "
            . implode(', ', array_keys($stale))
            . '. Regenerate the manifest with the builder.',
        );
    }

    public function testEnvDefaultIsAnEnvTemplateNotEnv(): void
    {
        // Belt-and-suspenders: .env must never appear in the manifest. .env.default may.
        self::assertNull(
            $this->manifest->entryByPath('.env'),
            '.env must never be a scaffold-managed path (it carries project-local secrets).',
        );
        $envDefault = $this->manifest->entryByPath('.env.default');
        self::assertNotNull($envDefault, 'Shipped manifest must declare .env.default.');
        self::assertSame(ScaffoldFileCategory::EnvTemplate, $envDefault->category);
    }
}
