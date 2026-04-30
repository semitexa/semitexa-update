<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

final readonly class DeploymentPlan
{
    /**
     * @param array<string, string> $installedPackages
     * @param list<PackageUpdate> $packageUpdates
     */
    public function __construct(
        public DeploymentConfig $config,
        public array $installedPackages,
        public array $packageUpdates,
        public ?string $privateLatestVersion,
        public ?string $selectedVersion,
        public bool $updateAvailable,
        public string $reason,
    ) {}
}
