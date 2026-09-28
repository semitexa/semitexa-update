<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

/**
 * What a project's own files say about its semitexa/* packages: composer.json
 * (declared), composer.lock (locked) and vendor/composer/installed.json
 * (installed). Read-only, no network.
 */
final class ComposerProjectState
{
    private const PREFIX = 'semitexa/';

    public function isDevConstraint(?string $declared): bool
    {
        if ($declared === null) {
            return false;
        }
        $d = strtolower(trim($declared));
        return $d === '@dev' || str_starts_with($d, 'dev-');
    }

    public function isWildcardConstraint(?string $declared): bool
    {
        if ($declared === null) {
            return false;
        }
        $d = trim($declared);
        return $d === '' || $d === '*' || str_starts_with($d, '^') || str_starts_with($d, '~')
            || str_contains($d, '||') || str_contains($d, ',') || str_contains($d, ' ')
            || str_contains($d, '>') || str_contains($d, '<');
    }

    /**
     * @return array<string, string>
     */
    public function readDeclared(string $projectRoot): array
    {
        $data = $this->readJson($projectRoot . '/composer.json');
        if ($data === null) {
            return [];
        }
        $declared = [];
        foreach (['require', 'require-dev'] as $bucket) {
            $entries = $data[$bucket] ?? [];
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $name => $constraint) {
                if (is_string($name) && is_string($constraint)) {
                    $declared[$name] = $constraint;
                }
            }
        }
        return $declared;
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    public function readLocked(string $projectRoot): array
    {
        $data = $this->readJson($projectRoot . '/composer.lock');
        if ($data === null) {
            return [[], []];
        }
        return $this->scanInstalledShape($data, ['packages', 'packages-dev']);
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    public function readInstalled(string $projectRoot): array
    {
        $data = $this->readJson($projectRoot . '/vendor/composer/installed.json');
        if ($data === null) {
            return [[], []];
        }
        if (isset($data['packages']) && is_array($data['packages'])) {
            return $this->scanInstalledShape($data, ['packages']);
        }
        return $this->scanInstalledShape(['packages' => $data], ['packages']);
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $buckets
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    private function scanInstalledShape(array $data, array $buckets): array
    {
        $versions = [];
        $pathRepos = [];
        foreach ($buckets as $bucket) {
            $entries = $data[$bucket] ?? [];
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $package) {
                if (!is_array($package)) {
                    continue;
                }
                $name = (string) ($package['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if ($this->isPathRepoEntry($package)) {
                    $pathRepos[$name] = true;
                }
                $version = ltrim((string) ($package['version'] ?? ''), 'v');
                if ($version !== '') {
                    $versions[$name] = $version;
                }
            }
        }
        return [$versions, $pathRepos];
    }

    /**
     * @param array<string, mixed> $package
     */
    private function isPathRepoEntry(array $package): bool
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
     * @param array<string, string> $declared
     * @param array<string, string> $locked
     * @param array<string, string> $installed
     * @return list<string>
     */
    public function collectSemitexaNames(array $declared, array $locked, array $installed): array
    {
        $names = [];
        foreach (array_merge(array_keys($declared), array_keys($locked), array_keys($installed)) as $name) {
            if (str_starts_with($name, self::PREFIX)) {
                $names[$name] = true;
            }
        }
        $list = array_keys($names);
        sort($list);
        return $list;
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function installedVersion(string $projectRoot, string $package): ?string
    {
        $data = $this->readJson($projectRoot . '/vendor/composer/installed.json');
        if ($data === null) {
            return null;
        }
        $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;
        if (!is_array($packages)) {
            return null;
        }
        foreach ($packages as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['name'] ?? null) === $package) {
                return ltrim((string) ($entry['version'] ?? ''), 'v') ?: null;
            }
        }
        return null;
    }

    /**
     * Concrete version of every non-path semitexa/* package, as installed.
     *
     * Installed rather than locked: the lock states an intention, vendor/ is
     * what the application will actually load. Path repositories are excluded —
     * their "version" is whatever the working copy happens to be.
     *
     * @return array<string, string>
     */
    public function semitexaVersions(string $projectRoot): array
    {
        [$installed, $installedPathRepos] = $this->readInstalled($projectRoot);
        [$locked, $lockPathRepos] = $this->readLocked($projectRoot);
        $pathRepos = $lockPathRepos + $installedPathRepos;

        $out = [];
        foreach ($installed as $name => $version) {
            if (!isset($pathRepos[$name])) {
                $out[$name] = $version;
            }
        }

        return $out;
    }
}
