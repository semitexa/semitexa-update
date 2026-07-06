<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Source;

use Semitexa\Update\Domain\Model\PackageUpdate;
use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;

final class PackagistReleaseSource
{
    /**
     * Diagnostics from the last discoverUpdates() call. A network failure
     * must never masquerade as "no update available" — callers surface these.
     *
     * @var list<string>
     */
    private array $warnings = [];

    /**
     * @return list<string>
     */
    public function lastWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param array<string, string> $installedPackages
     * @return list<PackageUpdate>
     */
    public function discoverUpdates(array $installedPackages): array
    {
        $this->warnings = [];
        $updates = [];

        foreach ($installedPackages as $packageName => $installedVersion) {
            $latestVersion = $this->fetchLatestStableVersion($packageName);
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
                source: 'packagist',
            );
        }

        usort(
            $updates,
            static fn(PackageUpdate $left, PackageUpdate $right): int => strcmp($left->packageName, $right->packageName),
        );

        return $updates;
    }

    private function fetchLatestStableVersion(string $packageName): ?string
    {
        $url = 'https://repo.packagist.org/p2/' . $packageName . '.json';
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "User-Agent: Semitexa-Dev-Auto-Deploy\r\n",
            ],
        ]);

        $json = @file_get_contents($url, false, $context);
        $statusCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1) {
            $statusCode = (int) $m[1];
        }

        if ($json === false) {
            $this->warnings[] = sprintf(
                'packagist unreachable for %s — treating as no update, but this may hide a newer release.',
                $packageName,
            );
            return null;
        }
        // 404 means the package simply is not published on Packagist (normal
        // for private packages in mixed mode) — that is a real "no update".
        if ($statusCode !== 404 && ($statusCode < 200 || $statusCode >= 300)) {
            $this->warnings[] = sprintf(
                'packagist answered HTTP %d for %s — treating as no update, but this may hide a newer release.',
                $statusCode,
                $packageName,
            );
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['packages'][$packageName]) || !is_array($data['packages'][$packageName])) {
            return null;
        }

        $versions = [];
        foreach ($data['packages'][$packageName] as $packageVersion) {
            if (!is_array($packageVersion)) {
                continue;
            }

            $version = ltrim((string) ($packageVersion['version'] ?? ''), 'v');
            if (!SemitexaReleaseVersion::isStable($version)) {
                continue;
            }

            $versions[] = $version;
        }

        return SemitexaReleaseVersion::latestStable($versions);
    }
}
