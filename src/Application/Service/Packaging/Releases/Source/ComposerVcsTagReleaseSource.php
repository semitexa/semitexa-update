<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Source;

use Semitexa\Update\Domain\Model\PackageUpdate;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;

final class ComposerVcsTagReleaseSource
{
    public function __construct(
        private readonly PrivateGitTagSource $tagSource = new PrivateGitTagSource(),
    ) {}

    /**
     * @param array<string, string> $installedPackages
     * @return list<PackageUpdate>
     */
    public function discoverUpdates(string $projectRoot, array $installedPackages): array
    {
        $repositoryMap = $this->repositoryMap($projectRoot);
        $updates = [];

        foreach ($installedPackages as $packageName => $installedVersion) {
            $repositoryUrl = $repositoryMap[$packageName] ?? null;
            if ($repositoryUrl === null) {
                continue;
            }

            $latestVersion = $this->tagSource->latestStableTag($repositoryUrl);
            if ($latestVersion === null) {
                continue;
            }

            if (SemitexaReleaseVersion::compare($latestVersion, $installedVersion) <= 0) {
                continue;
            }

            $updates[] = new PackageUpdate(
                packageName: $packageName,
                installedVersion: $installedVersion,
                latestVersion: $latestVersion,
                source: 'vcs',
            );
        }

        usort(
            $updates,
            static fn(PackageUpdate $left, PackageUpdate $right): int => strcmp($left->packageName, $right->packageName),
        );

        return $updates;
    }

    /**
     * @return array<string, string>
     */
    private function repositoryMap(string $projectRoot): array
    {
        $composerPath = $projectRoot . '/composer.json';
        if (!is_file($composerPath)) {
            return [];
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($composer)) {
            return [];
        }

        $map = [];
        foreach (($composer['repositories'] ?? []) as $repository) {
            if (!is_array($repository)) {
                continue;
            }

            if (($repository['type'] ?? null) !== 'vcs') {
                continue;
            }

            $url = $repository['url'] ?? null;
            if (!is_string($url) || trim($url) === '') {
                continue;
            }

            $packageName = $this->packageNameFromRepositoryUrl($url);
            if ($packageName === null) {
                continue;
            }

            $map[$packageName] ??= $url;
        }

        return $map;
    }

    private function packageNameFromRepositoryUrl(string $url): ?string
    {
        $normalized = rtrim(trim($url), '/');
        $repoName = basename($normalized, '.git');

        if (!str_starts_with($repoName, 'semitexa-')) {
            return null;
        }

        return 'semitexa/' . substr($repoName, strlen('semitexa-'));
    }
}
