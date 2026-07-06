<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Service;

use Semitexa\Update\Domain\Model\DeploymentPlan;
use Semitexa\Update\Domain\Model\PackageUpdate;
use Semitexa\Update\Application\Service\Packaging\Releases\Source\ComposerVcsTagReleaseSource;
use Semitexa\Update\Application\Service\Packaging\Releases\Source\PackagistReleaseSource;
use Semitexa\Update\Application\Service\Packaging\Releases\Source\PrivateGitTagSource;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\InstalledSemitexaPackageReader;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\ProjectDeploymentConfigLoader;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;

final class FrameworkDeploymentPlanner
{
    public function __construct(
        private readonly ProjectDeploymentConfigLoader $configLoader = new ProjectDeploymentConfigLoader(),
        private readonly InstalledSemitexaPackageReader $packageReader = new InstalledSemitexaPackageReader(),
        private readonly PackagistReleaseSource $packagistReleaseSource = new PackagistReleaseSource(),
        private readonly ComposerVcsTagReleaseSource $composerVcsTagReleaseSource = new ComposerVcsTagReleaseSource(),
        private readonly PrivateGitTagSource $privateGitTagSource = new PrivateGitTagSource(),
    ) {}

    public function plan(string $projectRoot): DeploymentPlan
    {
        $config = $this->configLoader->load();
        $classified = $this->packageReader->classify($projectRoot);
        $installedPackages = $classified->vendor;
        $localWorkspacePackages = $classified->localWorkspace;
        $packageUpdates = [];
        $privateLatestVersion = null;
        $sourceWarnings = [];

        if (in_array($config->sourceMode, ['packagist', 'mixed'], true)) {
            $packageUpdates = $this->mergePackageUpdates(
                $packageUpdates,
                $this->packagistReleaseSource->discoverUpdates($installedPackages),
            );
            $sourceWarnings = array_merge($sourceWarnings, $this->packagistReleaseSource->lastWarnings());
        }

        if (in_array($config->sourceMode, ['private', 'mixed'], true)) {
            $packageUpdates = $this->mergePackageUpdates(
                $packageUpdates,
                $this->composerVcsTagReleaseSource->discoverUpdates($projectRoot, $installedPackages),
            );
            $sourceWarnings = array_merge($sourceWarnings, $this->composerVcsTagReleaseSource->lastWarnings());
        }

        if (in_array($config->sourceMode, ['private', 'mixed'], true) && $config->privateRepositoryUrl !== null) {
            $this->privateGitTagSource->resetWarnings();
            $privateLatestVersion = $this->privateGitTagSource->latestStableTag($config->privateRepositoryUrl);
            $sourceWarnings = array_merge($sourceWarnings, $this->privateGitTagSource->lastWarnings());
        }

        $selectedVersion = $this->selectVersion($installedPackages, $packageUpdates, $privateLatestVersion);
        $updateAvailable = $selectedVersion !== null;
        $reason = $this->buildReason($config->enabled, $packageUpdates, $privateLatestVersion, $selectedVersion, $sourceWarnings);

        return new DeploymentPlan(
            config: $config,
            installedPackages: $installedPackages,
            packageUpdates: $packageUpdates,
            privateLatestVersion: $privateLatestVersion,
            selectedVersion: $selectedVersion,
            updateAvailable: $updateAvailable,
            reason: $reason,
            localWorkspacePackages: $localWorkspacePackages,
            sourceWarnings: $sourceWarnings,
        );
    }

    /**
     * @param array<string, string> $installedPackages
     * @param list<PackageUpdate> $packageUpdates
     */
    private function selectVersion(array $installedPackages, array $packageUpdates, ?string $privateLatestVersion): ?string
    {
        $candidates = array_map(static fn(PackageUpdate $update): string => $update->latestVersion, $packageUpdates);

        if ($privateLatestVersion !== null) {
            $installedMax = SemitexaReleaseVersion::latestStable(array_values($installedPackages));
            if ($installedMax === null || SemitexaReleaseVersion::compare($privateLatestVersion, $installedMax) > 0) {
                $candidates[] = $privateLatestVersion;
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, SemitexaReleaseVersion::compare(...));
        return array_pop($candidates) ?: null;
    }

    /**
     * @param list<PackageUpdate> $packageUpdates
     * @param list<string>        $sourceWarnings
     */
    private function buildReason(bool $enabled, array $packageUpdates, ?string $privateLatestVersion, ?string $selectedVersion, array $sourceWarnings = []): string
    {
        if (!$enabled) {
            return 'Automatic deployment is disabled by configuration.';
        }

        if ($selectedVersion === null) {
            if ($sourceWarnings !== []) {
                return sprintf(
                    'No newer stable Semitexa release was discovered, but discovery was DEGRADED (%d source warning(s)) — a newer release may exist.',
                    count($sourceWarnings),
                );
            }
            return 'No newer stable Semitexa release was discovered.';
        }

        if ($packageUpdates !== []) {
            $sources = array_values(array_unique(array_map(
                static fn(PackageUpdate $update): string => $update->source,
                $packageUpdates,
            )));

            if ($sources === ['vcs']) {
                return 'A newer Semitexa package set is available via VCS tag discovery.';
            }

            return 'A newer Semitexa package set is available via Composer package discovery.';
        }

        if ($privateLatestVersion !== null) {
            return 'A newer stable release tag was discovered in the configured private repository.';
        }

        throw new \UnexpectedValueException('Inconsistent deployment plan: selected version exists without any update source.');
    }

    /**
     * @param list<PackageUpdate> $existing
     * @param list<PackageUpdate> $incoming
     * @return list<PackageUpdate>
     */
    private function mergePackageUpdates(array $existing, array $incoming): array
    {
        $merged = [];

        foreach (array_merge($existing, $incoming) as $update) {
            $current = $merged[$update->packageName] ?? null;

            if (!$current instanceof PackageUpdate || SemitexaReleaseVersion::compare($update->latestVersion, $current->latestVersion) > 0) {
                $merged[$update->packageName] = $update;
            }
        }

        ksort($merged);

        return array_values($merged);
    }
}
