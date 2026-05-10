<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

/**
 * Classification of `semitexa/*` packages discovered in composer.lock.
 *
 * `vendor` packages are dist-installed (Packagist tag, VCS tag) and are valid
 * candidates for `update:packages:*` discovery and release manifests.
 *
 * `localWorkspace` packages are path-repository checkouts (typically under
 * `packages/semitexa-*` in a Semitexa monorepo development environment).
 * They are source code under active development, not Composer-updateable
 * dependencies, and must be excluded from update planning, version
 * discovery, and release manifest materialization.
 */
final readonly class InstalledSemitexaPackages
{
    /**
     * @param array<string, string>           $vendor          Composer-installed dist packages: name => version.
     * @param array<string, LocalWorkspacePackage> $localWorkspace Path-repository / symlinked checkouts: name => details.
     */
    public function __construct(
        public array $vendor,
        public array $localWorkspace,
    ) {
    }

    /**
     * Names of vendor-installed Semitexa packages, sorted.
     *
     * @return list<string>
     */
    public function vendorNames(): array
    {
        $names = array_keys($this->vendor);
        sort($names);
        return $names;
    }

    /**
     * Names of local workspace packages, sorted. These are the packages the
     * update workflow must NOT mutate, treat as update candidates, or include
     * in a release manifest.
     *
     * @return list<string>
     */
    public function localWorkspaceNames(): array
    {
        $names = array_keys($this->localWorkspace);
        sort($names);
        return $names;
    }

    public function hasVendor(): bool
    {
        return $this->vendor !== [];
    }

    public function hasLocalWorkspace(): bool
    {
        return $this->localWorkspace !== [];
    }
}
