<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Service\Scaffold;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldHasher;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestBuilder;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestLoader;
use Semitexa\Update\Application\Service\Scaffold\ScaffoldManifestPolicy;
use Semitexa\Update\Domain\Enum\ScaffoldFileCategory;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldFileEntry;
use Semitexa\Update\Domain\Model\Scaffold\ScaffoldManifest;

final class ScaffoldManifestBuilderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/semitexa-builder-' . bin2hex(random_bytes(8));
        mkdir($this->tmp . '/bin', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrm($this->tmp);
    }

    public function testHashIsDeterministicForRawBytes(): void
    {
        file_put_contents($this->tmp . '/bin/semitexa', "#!/usr/bin/env bash\nexit 0\n");
        $h1 = (new ScaffoldHasher())->hashFile($this->tmp . '/bin/semitexa');
        $h2 = (new ScaffoldHasher())->hashFile($this->tmp . '/bin/semitexa');
        self::assertSame($h1, $h2);
        self::assertSame(hash('sha256', "#!/usr/bin/env bash\nexit 0\n"), $h1);
    }

    public function testBuildIncludesEveryPolicyEntryFound(): void
    {
        $this->writeFullScaffold();

        $manifest = (new ScaffoldManifestBuilder())->build($this->tmp);

        self::assertSame(ScaffoldManifest::SCHEMA_VERSION, $manifest->schemaVersion);
        self::assertContains('bin/semitexa', $manifest->paths());

        $bin = $manifest->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertTrue($bin->critical);
        self::assertTrue($bin->preserveExecutable);
        self::assertSame(ScaffoldFileCategory::Executable, $bin->category);
        self::assertSame([], $bin->previousSha256, 'No prior manifest → previous_sha256 starts empty');
    }

    public function testBuildAbortsOnUncategorisedScaffoldFile(): void
    {
        $this->writeFullScaffold();
        file_put_contents($this->tmp . '/surprise.txt', 'who am i');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('uncategorised files');
        (new ScaffoldManifestBuilder())->build($this->tmp);
    }

    public function testPreviousHashesAccumulateAcrossRebuilds(): void
    {
        $this->writeFullScaffold(binContent: "#!/bin/sh\necho v1\n");
        $prior = (new ScaffoldManifestBuilder())->build($this->tmp);
        $v1Hash = $prior->entryByPath('bin/semitexa')?->currentSha256;
        self::assertNotNull($v1Hash);

        file_put_contents($this->tmp . '/bin/semitexa', "#!/bin/sh\necho v2\n");
        $rebuilt = (new ScaffoldManifestBuilder())->build($this->tmp, prior: $prior);

        $bin = $rebuilt->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertNotSame($v1Hash, $bin->currentSha256);
        self::assertSame([$v1Hash], $bin->previousSha256, 'Old current hash must roll into previous_sha256');
    }

    public function testRebuildWithIdenticalContentPreservesPreviousChain(): void
    {
        $this->writeFullScaffold();
        $v1 = (new ScaffoldManifestBuilder())->build($this->tmp);
        $v2 = (new ScaffoldManifestBuilder())->build($this->tmp, prior: $v1);

        $bin = $v2->entryByPath('bin/semitexa');
        $priorBin = $v1->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertNotNull($priorBin);
        self::assertSame($priorBin->currentSha256, $bin->currentSha256);
        self::assertSame($priorBin->previousSha256, $bin->previousSha256);
    }

    public function testGeneratedAtCanBeFixedForReproducibility(): void
    {
        $this->writeFullScaffold();
        $manifest = (new ScaffoldManifestBuilder())->build($this->tmp, generatedAt: '2026-05-11T15:30:00+00:00');
        self::assertSame('2026-05-11T15:30:00+00:00', $manifest->generatedAt);
    }

    public function testBuildOutputRoundtripsThroughLoader(): void
    {
        $this->writeFullScaffold();
        $built = (new ScaffoldManifestBuilder())->build($this->tmp, generatedAt: '2026-05-11T15:30:00+00:00');

        $tmpJson = sys_get_temp_dir() . '/built-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($tmpJson, json_encode($built->toArray()));

        $reloaded = (new ScaffoldManifestLoader())->load($tmpJson);
        self::assertSame($built->paths(), $reloaded->paths());
        foreach ($built->entries as $path => $expected) {
            $actual = $reloaded->entryByPath($path);
            self::assertNotNull($actual);
            self::assertSame($expected->currentSha256, $actual->currentSha256);
            self::assertSame($expected->previousSha256, $actual->previousSha256);
            self::assertSame($expected->category, $actual->category);
            self::assertSame($expected->critical, $actual->critical);
            self::assertSame($expected->preserveExecutable, $actual->preserveExecutable);
        }

        @unlink($tmpJson);
    }

    public function testShippedManifestMatchesRealScaffoldContent(): void
    {
        $scaffoldRoot = dirname(__DIR__, 5) . '/semitexa-installer/scaffold';
        if (!is_dir($scaffoldRoot)) {
            self::markTestSkipped('scaffold source dir not available in this checkout');
        }

        $shipped = (new ScaffoldManifestLoader())->load(
            dirname(__DIR__, 4) . '/resources/scaffold-manifest.json'
        );
        $rebuilt = (new ScaffoldManifestBuilder())->build($scaffoldRoot, generatedAt: $shipped->generatedAt);

        self::assertSame(
            $shipped->paths(),
            $rebuilt->paths(),
            'Shipped manifest and scaffold dir disagree on which files exist.',
        );
        foreach ($shipped->entries as $path => $shippedEntry) {
            $rebuiltEntry = $rebuilt->entryByPath($path);
            self::assertNotNull($rebuiltEntry);
            self::assertSame(
                $shippedEntry->currentSha256,
                $rebuiltEntry->currentSha256,
                "Shipped manifest current_sha256 for {$path} is stale — rebuild the manifest.",
            );
        }
    }

    public function testManifestIncludesBinSemitexaMarkedCritical(): void
    {
        $shipped = (new ScaffoldManifestLoader())->load(
            dirname(__DIR__, 4) . '/resources/scaffold-manifest.json'
        );
        $bin = $shipped->entryByPath('bin/semitexa');
        self::assertNotNull($bin);
        self::assertTrue($bin->critical);
        self::assertTrue($bin->preserveExecutable);
        self::assertSame(ScaffoldFileCategory::Executable, $bin->category);
    }

    private function writeFullScaffold(?string $binContent = null): void
    {
        $binContent ??= "#!/usr/bin/env bash\nexit 0\n";
        file_put_contents($this->tmp . '/bin/semitexa', $binContent);
        foreach ((new ScaffoldManifestPolicy())->defaults() as $relative => $_) {
            $absolute = $this->tmp . '/' . $relative;
            if ($relative === 'bin/semitexa') {
                continue;
            }
            $dir = dirname($absolute);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            if (!is_file($absolute)) {
                file_put_contents($absolute, "# stub for {$relative}\n");
            }
        }
    }

    private function rrm(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }
}
