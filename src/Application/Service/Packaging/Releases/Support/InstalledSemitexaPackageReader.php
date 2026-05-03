<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

final class InstalledSemitexaPackageReader
{
    /**
     * @return array<string, string>
     */
    public function read(string $projectRoot): array
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

        $packages = [];
        foreach (['packages', 'packages-dev'] as $bucket) {
            foreach ($data[$bucket] ?? [] as $package) {
                if (!is_array($package)) {
                    continue;
                }

                $name = (string) ($package['name'] ?? '');
                $version = ltrim((string) ($package['version'] ?? ''), 'v');
                if ($name === '' || $version === '' || !str_starts_with($name, 'semitexa/')) {
                    continue;
                }

                if (!SemitexaReleaseVersion::isValid($version)) {
                    continue;
                }

                $packages[$name] = $version;
            }
        }

        ksort($packages);
        return $packages;
    }
}
