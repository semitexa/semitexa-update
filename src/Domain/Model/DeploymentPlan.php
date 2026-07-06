<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

final readonly class DeploymentPlan
{
    /**
     * @param array<string, string>             $installedPackages       Vendor-installed Semitexa packages: name => version.
     * @param list<PackageUpdate>               $packageUpdates          Discovered upgrades for vendor packages only.
     * @param array<string, LocalWorkspacePackage> $localWorkspacePackages Path-repository checkouts excluded from update candidates.
     * @param list<string>                      $sourceWarnings          Discovery diagnostics (unreachable upstream, failed git ls-remote):
     *                                                                   "no update" alongside warnings means DEGRADED, not verified-current.
     */
    public function __construct(
        public DeploymentConfig $config,
        public array $installedPackages,
        public array $packageUpdates,
        public ?string $privateLatestVersion,
        public ?string $selectedVersion,
        public bool $updateAvailable,
        public string $reason,
        public array $localWorkspacePackages = [],
        public array $sourceWarnings = [],
    ) {}
}
