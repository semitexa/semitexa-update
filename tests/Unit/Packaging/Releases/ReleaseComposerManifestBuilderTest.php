<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\ReleaseComposerManifestBuilder;

final class ReleaseComposerManifestBuilderTest extends TestCase
{
    public function testBuildsReleaseManifestFromPathRepositoriesAndComposerLock(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-release-manifest-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/packages/semitexa-core', 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'packages/semitexa-core',
                    'options' => ['symlink' => true],
                ],
            ],
            'require' => [
                'php' => '^8.4',
                'semitexa/core' => '*',
            ],
            'require-dev' => [
                'semitexa/dev' => '*',
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'semitexa/core', 'version' => '1.1.62'],
            ],
            'packages-dev' => [
                ['name' => 'semitexa/dev', 'version' => '2026.04.03.1207'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/packages/semitexa-core/composer.json', json_encode([
            'name' => 'semitexa/core',
        ], JSON_THROW_ON_ERROR));

        exec(sprintf('git -C %s init -q', escapeshellarg($projectRoot . '/packages/semitexa-core')));
        exec(sprintf(
            'git -C %s remote add origin %s',
            escapeshellarg($projectRoot . '/packages/semitexa-core'),
            escapeshellarg('git@github.com:semitexa/semitexa-core.git'),
        ));

        $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);

        self::assertSame('1.1.62', $manifest['require']['semitexa/core']);
        self::assertSame('2026.04.03.1207', $manifest['require']['semitexa/dev']);
        self::assertArrayNotHasKey('semitexa/dev', $manifest['require-dev']);
        self::assertSame('stable', $manifest['minimum-stability']);
        self::assertSame([
            [
                'type' => 'vcs',
                'url' => 'https://github.com/semitexa/semitexa-core.git',
            ],
        ], $manifest['repositories']);
    }

    public function testNormalizesGitHubOriginsToHttpsForPrivateSemitexaRepositories(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-release-manifest-https-origin-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/packages/semitexa-core', 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'packages/semitexa-core',
                ],
            ],
            'require' => [
                'semitexa/core' => '*',
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'semitexa/core', 'version' => '1.1.62'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/packages/semitexa-core/composer.json', json_encode([
            'name' => 'semitexa/core',
        ], JSON_THROW_ON_ERROR));

        exec(sprintf('git -C %s init -q', escapeshellarg($projectRoot . '/packages/semitexa-core')));
        exec(sprintf(
            'git -C %s remote add origin %s',
            escapeshellarg($projectRoot . '/packages/semitexa-core'),
            escapeshellarg('https://github.com/semitexa/semitexa-core.git'),
        ));

        $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);

        self::assertSame([
            [
                'type' => 'vcs',
                'url' => 'https://github.com/semitexa/semitexa-core.git',
            ],
        ], $manifest['repositories']);
    }

    public function testMaterializesSemitexaConstraintsFromLockedVersions(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-release-manifest-constraints-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/packages/semitexa-core', 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'packages/semitexa-core',
                ],
            ],
            'require' => [
                'php' => '^8.4',
                'semitexa/core' => '^1.1',
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'semitexa/core', 'version' => '1.1.62'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/packages/semitexa-core/composer.json', json_encode([
            'name' => 'semitexa/core',
        ], JSON_THROW_ON_ERROR));

        $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);

        self::assertSame('1.1.62', $manifest['require']['semitexa/core']);
    }

    public function testPromotesSemitexaDevIntoRequireForOperationalReleaseRoots(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-release-manifest-operational-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/packages/semitexa-dev', 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'packages/semitexa-dev',
                ],
            ],
            'require' => [
                'php' => '^8.4',
            ],
            'require-dev' => [
                'semitexa/dev' => '*',
                'phpunit/phpunit' => '^10.0',
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [
                ['name' => 'semitexa/dev', 'version' => '1.0.0'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/packages/semitexa-dev/composer.json', json_encode([
            'name' => 'semitexa/dev',
            'license' => 'MIT',
        ], JSON_THROW_ON_ERROR));

        $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);

        self::assertSame('1.0.0', $manifest['require']['semitexa/dev']);
        self::assertArrayNotHasKey('semitexa/dev', $manifest['require-dev']);
        self::assertSame('^10.0', $manifest['require-dev']['phpunit/phpunit']);
    }

    public function testFallsBackToCanonicalGitHubRemoteWhenGitMetadataIsMissing(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-release-manifest-nogit-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/packages/semitexa-site', 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'packages/semitexa-site',
                    'options' => ['symlink' => true],
                ],
            ],
            'require' => [
                'semitexa/site' => '*',
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'semitexa/site', 'version' => '0.1.0'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/packages/semitexa-site/composer.json', json_encode([
            'name' => 'semitexa/site',
            'license' => 'proprietary',
        ], JSON_THROW_ON_ERROR));

        $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);

        self::assertSame([
            [
                'type' => 'vcs',
                'url' => 'https://github.com/semitexa/semitexa-site.git',
            ],
        ], $manifest['repositories']);
    }

    public function testTreatsArrayLicenseContainingProprietaryAsPrivateRepository(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-release-manifest-license-array-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/packages/semitexa-site', 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'packages/semitexa-site',
                ],
            ],
            'require' => [
                'semitexa/site' => '*',
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'semitexa/site', 'version' => '0.1.0'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/packages/semitexa-site/composer.json', json_encode([
            'name' => 'semitexa/site',
            'license' => ['MIT', 'Proprietary'],
        ], JSON_THROW_ON_ERROR));

        $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);

        self::assertSame([
            [
                'type' => 'vcs',
                'url' => 'https://github.com/semitexa/semitexa-site.git',
            ],
        ], $manifest['repositories']);
    }

    public function testOmitsRepositoryForOpenLicensedPackagesResolvedFromPackagist(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-release-manifest-public-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/packages/semitexa-cache', 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => 'packages/semitexa-cache',
                ],
            ],
            'require' => [
                'semitexa/cache' => '*',
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'semitexa/cache', 'version' => '1.0.0'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/packages/semitexa-cache/composer.json', json_encode([
            'name' => 'semitexa/cache',
            'license' => 'MIT',
        ], JSON_THROW_ON_ERROR));

        $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);

        // Open-licensed (public) package: no VCS repository entry — composer resolves
        // it from public Packagist over anonymous HTTPS (no deploy key/token needed).
        self::assertSame([], $manifest['repositories']);
        self::assertSame('1.0.0', $manifest['require']['semitexa/cache']);
    }

    public function testKeepsHttpsVcsEntryForProprietaryArrayLicensedPackage(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-release-manifest-private-multi-' . bin2hex(random_bytes(4));
        mkdir($projectRoot . '/packages/semitexa-os-site', 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                ['type' => 'path', 'url' => 'packages/semitexa-os-site'],
            ],
            'require' => ['semitexa/os-site' => '*'],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/composer.lock', json_encode([
            'packages' => [
                ['name' => 'semitexa/os-site', 'version' => '0.1.0'],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($projectRoot . '/packages/semitexa-os-site/composer.json', json_encode([
            'name' => 'semitexa/os-site',
            'license' => ['MIT', 'proprietary'],
        ], JSON_THROW_ON_ERROR));

        $manifest = (new ReleaseComposerManifestBuilder())->build($projectRoot);

        self::assertSame([
            [
                'type' => 'vcs',
                'url' => 'https://github.com/semitexa/semitexa-os-site.git',
            ],
        ], $manifest['repositories']);
    }
}
