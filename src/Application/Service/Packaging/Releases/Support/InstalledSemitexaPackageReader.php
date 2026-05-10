<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

use Semitexa\Update\Domain\Model\InstalledSemitexaPackages;
use Semitexa\Update\Domain\Model\LocalWorkspacePackage;

/**
 * Reads installed `semitexa/*` packages from composer.lock and classifies them
 * by Composer install source.
 *
 * The update lifecycle distinguishes two kinds of installs:
 *
 *   * vendor — dist-installed from Packagist or a tagged VCS reference. These
 *     are the only packages valid as targets for version discovery, release
 *     manifests, and auto-deploy planning.
 *   * local workspace — path-repository checkouts (or symlinks resolving into
 *     a packages/ directory). These are developer source code that must NOT be
 *     analyzed as update candidates or mutated by `bin/semitexa update*`.
 *
 * The classifier inspects each lock entry's `dist.type` and `source.type`
 * fields. Anything declaring `path` is a local workspace package; everything
 * else with a recognised Semitexa version is treated as vendor.
 */
final class InstalledSemitexaPackageReader
{
    /**
     * Vendor-installed Semitexa packages keyed by name. Local workspace
     * packages are excluded.
     *
     * @return array<string, string>
     */
    public function read(string $projectRoot): array
    {
        return $this->classify($projectRoot)->vendor;
    }

    /**
     * Full classification of installed `semitexa/*` packages: vendor and
     * local-workspace, side by side. Use this when callers need to know
     * which packages to skip with a transparent reason.
     */
    public function classify(string $projectRoot): InstalledSemitexaPackages
    {
        $entries = $this->lockEntries($projectRoot);

        $vendor = [];
        $local = [];

        foreach ($entries as $package) {
            $name = (string) ($package['name'] ?? '');
            if ($name === '' || !str_starts_with($name, 'semitexa/')) {
                continue;
            }

            $version = ltrim((string) ($package['version'] ?? ''), 'v');
            if ($version === '') {
                continue;
            }

            if ($this->isPathRepositoryEntry($package)) {
                unset($vendor[$name]);
                $local[$name] = new LocalWorkspacePackage(
                    name: $name,
                    version: $version,
                    sourceUrl: $this->extractSourceUrl($package),
                );
                continue;
            }

            if (isset($local[$name])) {
                continue;
            }

            if (!SemitexaReleaseVersion::isValid($version)) {
                continue;
            }

            $vendor[$name] = $version;
        }

        ksort($vendor);
        ksort($local);

        return new InstalledSemitexaPackages($vendor, $local);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lockEntries(string $projectRoot): array
    {
        $lockPath = $projectRoot . '/composer.lock';
        if (!is_file($lockPath)) {
            return [];
        }

        $json = file_get_contents($lockPath);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $entries = [];
        foreach (['packages', 'packages-dev'] as $bucket) {
            $bucketEntries = $data[$bucket] ?? [];
            if (!is_array($bucketEntries)) {
                continue;
            }
            foreach ($bucketEntries as $package) {
                if (is_array($package)) {
                    $entries[] = $package;
                }
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function isPathRepositoryEntry(array $package): bool
    {
        foreach (['dist', 'source'] as $key) {
            $section = $package[$key] ?? null;
            if (is_array($section) && ($section['type'] ?? null) === 'path') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $package
     */
    private function extractSourceUrl(array $package): string
    {
        foreach (['dist', 'source'] as $key) {
            $section = $package[$key] ?? null;
            if (is_array($section) && ($section['type'] ?? null) === 'path') {
                $url = $section['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }
}
