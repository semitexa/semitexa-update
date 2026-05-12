<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
use Semitexa\Update\Domain\Enum\ScaffoldFileHashMatch;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;

final class ScaffoldManifestLoaderTest extends TestCase
{
    private const HASH_BIN_CURRENT      = '2222222222222222222222222222222222222222222222222222222222222222';
    private const HASH_BIN_PREVIOUS     = '1111111111111111111111111111111111111111111111111111111111111111';
    private const HASH_COMPOSE_CURRENT  = '3333333333333333333333333333333333333333333333333333333333333333';
    private const HASH_NEVER_SEEN       = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    public function testLoadsValidManifest(): void
    {
        $manifest = (new ScaffoldManifestLoader())->load($this->writeManifest($this->validData()));

        self::assertSame(ScaffoldManifest::SCHEMA_VERSION, $manifest->schemaVersion);
        self::assertSame(['bin/semitexa', 'docker-compose.yml'], $manifest->paths());

        $bin = $manifest->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertSame(ScaffoldFileCategory::Executable, $bin->category);
        self::assertTrue($bin->critical);
        self::assertTrue($bin->preserveExecutable);
        self::assertSame([self::HASH_BIN_PREVIOUS], $bin->previousSha256);
    }

    public function testCurrentHashLookupReturnsCurrent(): void
    {
        $bin = $this->loadValid()->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertSame(ScaffoldFileHashMatch::Current, $bin->classifyHash(self::HASH_BIN_CURRENT));
    }

    public function testPreviousHashLookupReturnsKnownPrevious(): void
    {
        $bin = $this->loadValid()->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertSame(ScaffoldFileHashMatch::KnownPrevious, $bin->classifyHash(self::HASH_BIN_PREVIOUS));
    }

    public function testUnrecognisedHashIsUnknown(): void
    {
        $bin = $this->loadValid()->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertSame(ScaffoldFileHashMatch::Unknown, $bin->classifyHash(self::HASH_NEVER_SEEN));
    }

    public function testMissingFilePathReturnsNullEntry(): void
    {
        self::assertNull($this->loadValid()->entryByPath('not/in/manifest.txt'));
    }

    public function testCriticalAndExecutableMetadataPreserved(): void
    {
        $manifest = $this->loadValid();

        $bin = $manifest->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertTrue($bin->critical);
        self::assertTrue($bin->preserveExecutable);

        $compose = $manifest->entryByPath('docker-compose.yml');
        self::assertNotNull($compose);
        self::assertFalse($compose->critical);
        self::assertFalse($compose->preserveExecutable);
    }

    public function testRejectsMissingSchemaVersion(): void
    {
        $data = $this->validData();
        unset($data['schema_version']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported scaffold manifest schema_version');
        (new ScaffoldManifestLoader())->load($this->writeManifest($data));
    }

    public function testRejectsWrongSchemaVersion(): void
    {
        $data = $this->validData();
        $data['schema_version'] = 'semitexa.scaffold-manifest/v9';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported scaffold manifest schema_version "semitexa.scaffold-manifest/v9"');
        (new ScaffoldManifestLoader())->load($this->writeManifest($data));
    }

    public function testRejectsNonSha256CurrentHash(): void
    {
        $data = $this->validData();
        $data['files'][0]['current_sha256'] = 'not-a-real-hash';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid current_sha256');
        (new ScaffoldManifestLoader())->load($this->writeManifest($data));
    }

    public function testRejectsInvalidCategory(): void
    {
        $data = $this->validData();
        $data['files'][0]['category'] = 'nope';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid category: nope');
        (new ScaffoldManifestLoader())->load($this->writeManifest($data));
    }

    public function testRejectsDuplicatePaths(): void
    {
        $data = $this->validData();
        $data['files'][] = $data['files'][0];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate entry');
        (new ScaffoldManifestLoader())->load($this->writeManifest($data));
    }

    public function testRejectsInvalidPreviousHashElement(): void
    {
        $data = $this->validData();
        $data['files'][0]['previous_sha256'] = ['not-a-hash'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid previous_sha256 element');
        (new ScaffoldManifestLoader())->load($this->writeManifest($data));
    }

    public function testEntriesMatchingHashFindsCurrentAndPrevious(): void
    {
        $manifest = $this->loadValid();

        $previousMatches = $manifest->entriesMatchingHash(self::HASH_BIN_PREVIOUS);
        self::assertCount(1, $previousMatches);
        self::assertSame('bin/semitexa', $previousMatches[0]->path);

        $currentMatches = $manifest->entriesMatchingHash(self::HASH_COMPOSE_CURRENT);
        self::assertCount(1, $currentMatches);
        self::assertSame('docker-compose.yml', $currentMatches[0]->path);

        self::assertSame([], $manifest->entriesMatchingHash(self::HASH_NEVER_SEEN));
    }

    public function testShippedManifestParsesCleanly(): void
    {
        $shipped = dirname(__DIR__, 4) . '/resources/scaffold-manifest.json';
        $manifest = (new ScaffoldManifestLoader())->load($shipped);

        self::assertContains('bin/semitexa', $manifest->paths());
        $bin = $manifest->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertTrue($bin->critical, 'bin/semitexa must be marked critical in the shipped manifest');
        self::assertTrue($bin->preserveExecutable, 'bin/semitexa must preserve the executable bit');
        self::assertSame(ScaffoldFileCategory::Executable, $bin->category);
    }

    private function loadValid(): ScaffoldManifest
    {
        return (new ScaffoldManifestLoader())->load($this->writeManifest($this->validData()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validData(): array
    {
        return [
            'schema_version' => ScaffoldManifest::SCHEMA_VERSION,
            'generated_at' => '2026-05-11T00:00:00+00:00',
            'files' => [
                [
                    'path' => 'bin/semitexa',
                    'current_sha256' => self::HASH_BIN_CURRENT,
                    'previous_sha256' => [self::HASH_BIN_PREVIOUS],
                    'category' => 'executable',
                    'critical' => true,
                    'auto_update' => true,
                    'preserve_executable' => true,
                    'notes' => 'CLI entry point.',
                ],
                [
                    'path' => 'docker-compose.yml',
                    'current_sha256' => self::HASH_COMPOSE_CURRENT,
                    'previous_sha256' => [],
                    'category' => 'infrastructure',
                    'critical' => false,
                    'auto_update' => true,
                    'preserve_executable' => false,
                    'notes' => '',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeManifest(array $data): string
    {
        $tmp = sys_get_temp_dir() . '/semitexa-manifest-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($tmp, json_encode($data));
        return $tmp;
    }
}
