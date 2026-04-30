<?php

declare(strict_types=1);

namespace Semitexa\Update\Tests\Unit\Packaging\Releases;

use PHPUnit\Framework\TestCase;
use Semitexa\Update\Application\Service\Packaging\Releases\Source\ComposerVcsTagReleaseSource;

final class ComposerVcsTagReleaseSourceTest extends TestCase
{
    public function testDiscoversUpdatesFromComposerVcsRepositories(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-vcs-release-source-' . bin2hex(random_bytes(4));
        $repositoryRoot = $projectRoot . '/repos/semitexa-site';

        mkdir($repositoryRoot, 0777, true);

        exec(sprintf('git -C %s init -q', escapeshellarg($repositoryRoot)));
        exec(sprintf('git -C %s config user.email %s', escapeshellarg($repositoryRoot), escapeshellarg('dev@semitexa.test')));
        exec(sprintf('git -C %s config user.name %s', escapeshellarg($repositoryRoot), escapeshellarg('Semitexa Dev')));
        file_put_contents($repositoryRoot . '/README.md', "site\n");
        exec(sprintf('git -C %s add README.md', escapeshellarg($repositoryRoot)));
        exec(sprintf('git -C %s commit -qm %s', escapeshellarg($repositoryRoot), escapeshellarg('init')));
        exec(sprintf('git -C %s tag 0.1.4', escapeshellarg($repositoryRoot)));

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'vcs',
                    'url' => $repositoryRoot,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $updates = (new ComposerVcsTagReleaseSource())->discoverUpdates($projectRoot, [
            'semitexa/site' => '0.1.0',
            'semitexa/core' => '1.1.58',
        ]);

        self::assertCount(1, $updates);
        self::assertSame('semitexa/site', $updates[0]->packageName);
        self::assertSame('0.1.0', $updates[0]->installedVersion);
        self::assertSame('0.1.4', $updates[0]->latestVersion);
        self::assertSame('vcs', $updates[0]->source);
    }

    public function testIgnoresRepositoriesThatDoNotMapToSemitexaPackages(): void
    {
        $projectRoot = sys_get_temp_dir() . '/semitexa-vcs-release-source-ignore-' . bin2hex(random_bytes(4));
        mkdir($projectRoot, 0777, true);

        file_put_contents($projectRoot . '/composer.json', json_encode([
            'repositories' => [
                [
                    'type' => 'vcs',
                    'url' => 'https://github.com/example/not-semitexa.git',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $updates = (new ComposerVcsTagReleaseSource())->discoverUpdates($projectRoot, [
            'semitexa/site' => '0.1.0',
        ]);

        self::assertSame([], $updates);
    }
}
