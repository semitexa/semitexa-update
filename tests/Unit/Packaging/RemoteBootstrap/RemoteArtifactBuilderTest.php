<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\RemoteBootstrap;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Packaging\RemoteBootstrap\Support\RemoteArtifactBuilder;

final class RemoteArtifactBuilderTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/semitexa-remote-artifact-test-' . uniqid('', true);
        mkdir($this->projectRoot . '/src', 0777, true);
        mkdir($this->projectRoot . '/vendor', 0777, true);
        mkdir($this->projectRoot . '/var/cache', 0777, true);
        mkdir($this->projectRoot . '/.codex', 0777, true);

        file_put_contents($this->projectRoot . '/composer.json', "{}\n");
        file_put_contents($this->projectRoot . '/.env', "APP_ENV=dev\n");
        file_put_contents($this->projectRoot . '/src/App.php', "<?php\n");
        file_put_contents($this->projectRoot . '/vendor/ignored.php', "<?php\n");
        file_put_contents($this->projectRoot . '/var/cache/ignored.txt', "ignored\n");
        file_put_contents($this->projectRoot . '/.codex/ignored.md', "# ignored\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectRoot));
    }

    public function testBuildsTarballAndExcludesDevelopmentPaths(): void
    {
        $artifact = (new RemoteArtifactBuilder())->build($this->projectRoot);

        self::assertFileExists($artifact->path);
        self::assertGreaterThan(0, $artifact->sizeBytes);
        self::assertSame(hash_file('sha256', $artifact->path), $artifact->sha256);

        $listing = shell_exec('tar -tzf ' . escapeshellarg($artifact->path));
        self::assertIsString($listing);
        self::assertStringContainsString('./src/App.php', $listing);
        self::assertStringNotContainsString('./vendor/ignored.php', $listing);
        self::assertStringNotContainsString('./var/cache/ignored.txt', $listing);
        self::assertStringNotContainsString('./.env', $listing);
        self::assertStringNotContainsString('./.codex/ignored.md', $listing);

        @unlink($artifact->path);
    }

    public function testBuildsReleaseArtifactWithoutPackagesAndWithVendorContents(): void
    {
        mkdir($this->projectRoot . '/packages/semitexa-site/src', 0777, true);

        file_put_contents($this->projectRoot . '/packages/semitexa-site/src/Site.php', "<?php\n");
        file_put_contents($this->projectRoot . '/composer.release.json', "{\n  \"name\": \"release/root\",\n  \"require\": {\n    \"php\": \"^8.4\"\n  }\n}\n");

        $artifact = (new RemoteArtifactBuilder())->build($this->projectRoot);
        $extractRoot = sys_get_temp_dir() . '/semitexa-remote-artifact-extract-' . uniqid('', true);
        mkdir($extractRoot, 0777, true);

        exec(
            sprintf(
                'tar -xzf %s -C %s',
                escapeshellarg($artifact->path),
                escapeshellarg($extractRoot),
            ),
        );

        self::assertFileExists($extractRoot . '/composer.json');
        self::assertStringContainsString('release/root', (string) file_get_contents($extractRoot . '/composer.json'));
        self::assertFileDoesNotExist($extractRoot . '/packages/semitexa-site/src/Site.php');
        self::assertFileExists($extractRoot . '/vendor/autoload.php');
        self::assertFileDoesNotExist($extractRoot . '/composer.lock');
        self::assertFileDoesNotExist($extractRoot . '/composer.release.json');

        exec('rm -rf ' . escapeshellarg($extractRoot));
        @unlink($artifact->path);
    }
}
