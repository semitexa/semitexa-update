<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\Releases\Source;

use Semitexa\Update\Packaging\Releases\Data\PackageUpdate;
use Semitexa\Update\Packaging\Releases\Support\SemitexaReleaseVersion;

final class PackagistReleaseSource
{
    /**
     * @param array<string, string> $installedPackages
     * @return list<PackageUpdate>
     */
    public function discoverUpdates(array $installedPackages): array
    {
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
        if ($json === false) {
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
