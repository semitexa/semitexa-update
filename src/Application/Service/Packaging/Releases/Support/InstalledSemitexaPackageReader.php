<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

use Semitexa\Update\Domain\Model\InstalledSemitexaPackages;
use Semitexa\Update\Domain\Model\LocalWorkspacePackage;

/**
 * Reads installed `semitexa/*` packages from `vendor/composer/installed.json`
 * and classifies them by Composer install source.
 *
 * It reads vendor, not composer.lock, because the lock records an intention
 * and vendor records what the autoloader will actually serve. The two diverge
 * whenever an install was skipped, interrupted or failed — and planning
 * against the intention is how a deployment reports "already current" while
 * the workers keep running the previous release. composer.lock stays as the
 * fallback for a project whose vendor has not been installed yet.
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
        $entries = $this->installedEntries($projectRoot);

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
    private function installedEntries(string $projectRoot): array
    {
        // installed.json keeps dev and non-dev packages in one `packages`
        // array; composer.lock splits them. Either shape carries the
        // name/version/dist/source fields the classifier needs.
        $vendor = $this->readPackages($projectRoot . '/vendor/composer/installed.json', ['packages']);
        if ($vendor !== null) {
            return $vendor;
        }

        return $this->readPackages($projectRoot . '/composer.lock', ['packages', 'packages-dev']) ?? [];
    }

    /**
     * Null means the file could not be read at all, which is what separates
     * "nothing installed" from "no such file" — only the latter may fall
     * through to the lock.
     *
     * @param  list<string> $buckets
     * @return list<array<string, mixed>>|null
     */
    private function readPackages(string $path, array $buckets): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $entries = [];
        foreach ($buckets as $bucket) {
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
