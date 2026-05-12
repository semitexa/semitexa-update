<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestPolicy;
use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
use Semitexa\Update\Domain\Enum\ScaffoldFileHashMatch;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;

/**
 * Locks in the policy + content invariants of the SHIPPED manifest at
 * packages/semitexa-update/resources/scaffold-manifest.json. Builder tests
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

    private ScaffoldManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = (new ScaffoldManifestLoader())->load(
            dirname(__DIR__, 4) . '/resources/scaffold-manifest.json',
        );
    }

    public function testShippedManifestSchemaVersionIsV1(): void
    {
        self::assertSame(ScaffoldManifest::SCHEMA_VERSION, $this->manifest->schemaVersion);
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

    public function testShippedManifestMatchesUpdateResourcesScaffoldByteForByte(): void
    {
        $scaffoldRoot = dirname(__DIR__, 4) . '/resources/scaffold';
        if (!is_dir($scaffoldRoot)) {
            self::markTestSkipped('Update resources scaffold not available in this checkout.');
        }

        $hasher = new ScaffoldHasher();
        $stale = [];
        foreach ($this->manifest->entries as $entry) {
            $absolute = $scaffoldRoot . '/' . $entry->path;
            self::assertFileExists(
                $absolute,
                "Manifest references {$entry->path} but the file is missing from the runtime scaffold dir.",
            );
            $actual = $hasher->hashFile($absolute);
            if ($actual !== $entry->currentSha256) {
                $stale[$entry->path] = ['manifest' => $entry->currentSha256, 'scaffold' => $actual];
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
