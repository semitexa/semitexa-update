<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Update\Domain\Enum\PackageDriftStatus;
use Semitexa\Update\Domain\Model\PackageDrift\PackageDriftEntry;
use Semitexa\Update\Domain\Model\PackageDrift\PackageDriftReport;

/**
 * Read-only inspector that detects local Composer drift across `semitexa/*`
 * packages. It crosses three files in the project root:
 *
 *   - composer.json                     (declared constraint / exact pin)
 *   - composer.lock                     (locked concrete version)
 *   - vendor/composer/installed.json    (installed concrete version)
 *
 * No network calls. No mutations. No composer subprocess. The inspector
 * produces a {@see PackageDriftReport} that callers (the update command,
 * tests, future scaffold-sync renderer) consume.
 *
 * "Latest upstream available" is intentionally NOT computed here — it
 * requires Packagist or a comparable HTTP source, and ships behind a
 * separate --check-upstream extension point as a follow-up slice.
 */
final class PackageDriftInspector
{
    private const PREFIX = 'semitexa/';

    /**
     * @param list<string> $packagePrefixes  Names beginning with any of these are inspected. Defaults to `semitexa/`.
     */
    public function inspect(string $projectRoot, array $packagePrefixes = [self::PREFIX]): PackageDriftReport
    {
        $declared = $this->readDeclared($projectRoot);
        [$locked, $lockPathRepos] = $this->readLocked($projectRoot);
        [$installed, $installedPathRepos] = $this->readInstalled($projectRoot);

        $names = $this->collectNames($declared, $locked, $installed, $packagePrefixes);
        $pathRepos = $lockPathRepos + $installedPathRepos;

        $entries = [];
        foreach ($names as $name) {
            $entries[] = $this->classify(
                $name,
                $declared[$name] ?? null,
                $locked[$name] ?? null,
                $installed[$name] ?? null,
                isset($pathRepos[$name]),
            );
        }

        [$coherent, $dates] = $this->releaseSetCoherence($entries);
        if (!$coherent) {
            $entries = $this->markMixedReleaseSet($entries, $dates);
        }

        return new PackageDriftReport($entries, $coherent, $dates);
    }

    private function classify(
        string $name,
        ?string $declared,
        ?string $locked,
        ?string $installed,
        bool $isPathRepo,
    ): PackageDriftEntry {
        if ($isPathRepo) {
            return new PackageDriftEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                upstream: null,
                status: PackageDriftStatus::PathRepository,
                actionHint: 'path repository — drift detection skipped',
            );
        }

        if ($this->isDevConstraint($declared)) {
            return new PackageDriftEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                upstream: null,
                status: PackageDriftStatus::DevConstraint,
                actionHint: 'dev constraint — release pinning intentionally skipped',
            );
        }

        if ($locked === null) {
            return new PackageDriftEntry(
                name: $name,
                declared: $declared,
                locked: null,
                installed: $installed,
                upstream: null,
                status: PackageDriftStatus::MissingFromLock,
                actionHint: 'declared in composer.json but absent from composer.lock — run composer update ' . $name,
            );
        }

        if ($installed === null) {
            return new PackageDriftEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: null,
                upstream: null,
                status: PackageDriftStatus::MissingFromVendor,
                actionHint: 'locked but not installed — run composer install',
            );
        }

        if ($this->isExactConstraint($declared) && $declared !== $locked) {
            return new PackageDriftEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                upstream: null,
                status: PackageDriftStatus::LockStale,
                actionHint: 'composer.json pin differs from composer.lock — run composer update ' . $name,
            );
        }

        if ($locked !== $installed) {
            return new PackageDriftEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                upstream: null,
                status: PackageDriftStatus::VendorStale,
                actionHint: 'composer.lock differs from installed vendor — run composer install',
            );
        }

        if ($this->isExactConstraint($declared) && $declared !== $installed) {
            return new PackageDriftEntry(
                name: $name,
                declared: $declared,
                locked: $locked,
                installed: $installed,
                upstream: null,
                status: PackageDriftStatus::VersionMismatch,
                actionHint: 'declared/locked/installed disagree in an unexpected way — investigate manually',
            );
        }

        return new PackageDriftEntry(
            name: $name,
            declared: $declared,
            locked: $locked,
            installed: $installed,
            upstream: null,
            status: PackageDriftStatus::Clean,
            actionHint: '',
        );
    }

    /**
     * @param list<PackageDriftEntry> $entries
     * @return array{0: bool, 1: list<string>}
     */
    private function releaseSetCoherence(array $entries): array
    {
        $dates = [];
        foreach ($entries as $entry) {
            if ($entry->installed === null) {
                continue;
            }
            $date = $this->extractReleaseDate($entry->installed);
            if ($date === null) {
                continue;
            }
            $dates[$date] = true;
        }
        $distinct = array_keys($dates);
        sort($distinct);
        return [count($distinct) <= 1, $distinct];
    }

    /**
     * @param list<PackageDriftEntry>  $entries
     * @param list<string>             $mixedDates
     * @return list<PackageDriftEntry>
     */
    private function markMixedReleaseSet(array $entries, array $mixedDates): array
    {
        $result = [];
        foreach ($entries as $entry) {
            if ($entry->status !== PackageDriftStatus::Clean) {
                $result[] = $entry;
                continue;
            }
            $result[] = new PackageDriftEntry(
                name: $entry->name,
                declared: $entry->declared,
                locked: $entry->locked,
                installed: $entry->installed,
                upstream: $entry->upstream,
                status: PackageDriftStatus::MixedReleaseSet,
                actionHint: 'semitexa/* set spans multiple release dates (' . implode(', ', $mixedDates)
                    . ') — align to a single release with composer update',
            );
        }
        return $result;
    }

    private function extractReleaseDate(string $version): ?string
    {
        if (preg_match('/^(\d{4}\.\d{2}\.\d{2})\.\d{4}(?:-[a-z0-9]+)?$/i', $version, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private function isDevConstraint(?string $declared): bool
    {
        if ($declared === null) {
            return false;
        }
        $d = strtolower(trim($declared));
        return $d === '@dev' || str_starts_with($d, 'dev-');
    }

    private function isExactConstraint(?string $declared): bool
    {
        if ($declared === null) {
            return false;
        }
        $d = trim($declared);
        if ($d === '' || $d === '*' || str_starts_with($d, '^') || str_starts_with($d, '~')
            || str_contains($d, '||') || str_contains($d, ',') || str_contains($d, ' ')
            || str_contains($d, '>') || str_contains($d, '<') || str_contains($d, '=')) {
            return false;
        }
        return preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}(?:-[a-z0-9]+)?$/i', $d) === 1
            || preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9]+)?$/i', $d) === 1;
    }

    /**
     * @return array<string, string>  package name => declared constraint
     */
    private function readDeclared(string $projectRoot): array
    {
        $path = $projectRoot . '/composer.json';
        $data = $this->readJson($path);
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
     * @return array{0: array<string, string>, 1: array<string, true>}  versions, pathRepoSet
     */
    private function readLocked(string $projectRoot): array
    {
        $path = $projectRoot . '/composer.lock';
        $data = $this->readJson($path);
        if ($data === null) {
            return [[], []];
        }
        $versions = [];
        $pathRepos = [];
        foreach (['packages', 'packages-dev'] as $bucket) {
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
     * @return array{0: array<string, string>, 1: array<string, true>}
     */
    private function readInstalled(string $projectRoot): array
    {
        $path = $projectRoot . '/vendor/composer/installed.json';
        $data = $this->readJson($path);
        if ($data === null) {
            return [[], []];
        }
        $packages = $data['packages'] ?? $data;
        if (!is_array($packages)) {
            return [[], []];
        }
        $versions = [];
        $pathRepos = [];
        foreach ($packages as $package) {
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
     * @param list<string>          $packagePrefixes
     * @return list<string>
     */
    private function collectNames(array $declared, array $locked, array $installed, array $packagePrefixes): array
    {
        $names = [];
        foreach (array_merge(array_keys($declared), array_keys($locked), array_keys($installed)) as $name) {
            foreach ($packagePrefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $names[$name] = true;
                    break;
                }
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
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
