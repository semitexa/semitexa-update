<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;
use Semitexa\Update\Domain\Model\Composer\NewSkeletonPackage;

/**
 * Answers "which semitexa/* packages does the CURRENT ultimate skeleton ship
 * that this consumer project never learned about?" — projects created from an
 * older skeleton do not gain new root requires on composer update, so a
 * package added to ultimate later (media, storage, ...) is invisible to them.
 *
 * Read-only and advisory: the caller renders proposals; nothing is installed.
 * Upstream metadata comes from Packagist p2; any network/parse failure yields
 * null so the update flow degrades to "no advisory" instead of failing.
 */
final class SkeletonRequireDiff
{
    private const ENDPOINT = 'https://repo.packagist.org/p2/%s.json';
    private const PREFIX = 'semitexa/';
    private const DESCRIPTION_FETCH_CAP = 10;

    /** @var callable(string): (list<array<string, mixed>>|null) */
    private $fetchRows;

    /**
     * @param callable(string): (list<array<string, mixed>>|null) $fetchRows Test seam: package name → expanded-ready p2 rows.
     */
    public function __construct(
        private readonly string $skeletonPackage = 'semitexa/ultimate',
        private readonly int $timeoutSeconds = 8,
        ?callable $fetchRows = null,
    ) {
        $this->fetchRows = $fetchRows ?? $this->packagistFetcher(...);
    }

    /**
     * @return list<NewSkeletonPackage>|null null when upstream metadata is unavailable
     */
    public function missingPackages(string $projectRoot): ?array
    {
        $skeletonRequire = $this->latestSkeletonRequire();
        if ($skeletonRequire === null) {
            return null;
        }

        $declared = $this->declaredPackages($projectRoot);
        if ($declared === null) {
            // Unreadable/unparsable composer.json: proposing "everything is
            // missing" would be noise — degrade to no advisory instead.
            return null;
        }

        $descriptionsFetched = 0;
        $missing = [];
        foreach ($skeletonRequire as $name => $pin) {
            if (!is_string($name) || !is_string($pin)) {
                continue;
            }
            if (!str_starts_with($name, self::PREFIX) || $name === $this->skeletonPackage) {
                continue;
            }
            if (isset($declared[$name])) {
                continue;
            }
            // Descriptions cost one extra Packagist request per proposal; cap
            // them so a far-behind consumer doesn't pay N x timeout on every
            // update run. Uncapped entries still propose, just undescribed.
            $description = '';
            if ($descriptionsFetched < self::DESCRIPTION_FETCH_CAP) {
                $descriptionsFetched++;
                $description = $this->descriptionOf($name);
            }
            $missing[] = new NewSkeletonPackage(
                name: $name,
                pinnedVersion: $pin,
                description: $description,
            );
        }

        return $missing;
    }

    /**
     * @return array<string, string>|null require map of the latest stable skeleton release
     */
    private function latestSkeletonRequire(): ?array
    {
        $rows = ($this->fetchRows)($this->skeletonPackage);
        if ($rows === null || $rows === []) {
            return null;
        }

        $expanded = P2MetadataExpander::expand($rows);

        $versions = [];
        foreach ($expanded as $row) {
            $v = (string) ($row['version'] ?? '');
            if (preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $v) === 1) {
                $versions[$v] = $row;
            }
        }
        if ($versions === []) {
            return null;
        }

        $latest = SemitexaReleaseVersion::latestStable(array_keys($versions));
        if ($latest === null) {
            return null;
        }

        $require = $versions[$latest]['require'] ?? null;

        return is_array($require) ? $require : null;
    }

    private function descriptionOf(string $package): string
    {
        $rows = ($this->fetchRows)($package);
        if ($rows === null || $rows === []) {
            return '';
        }
        $expanded = P2MetadataExpander::expand($rows);

        return (string) ($expanded[0]['description'] ?? '');
    }

    /**
     * @return array<string, true>|null null when composer.json is unreadable or unparsable
     */
    private function declaredPackages(string $projectRoot): ?array
    {
        $path = $projectRoot . '/composer.json';
        if (!is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        $data = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return null;
        }

        $declared = [];
        foreach (['require', 'require-dev'] as $bucket) {
            $entries = $data[$bucket] ?? [];
            if (!is_array($entries)) {
                continue;
            }
            foreach (array_keys($entries) as $name) {
                if (is_string($name)) {
                    $declared[$name] = true;
                }
            }
        }

        return $declared;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function packagistFetcher(string $package): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "User-Agent: Semitexa-Update/SkeletonRequireDiff\r\n",
                'ignore_errors' => false,
            ],
        ]);
        $body = @file_get_contents(sprintf(self::ENDPOINT, $package), false, $ctx);
        if ($body === false) {
            return null;
        }
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $rows = is_array($data) ? ($data['packages'][$package] ?? null) : null;

        return is_array($rows) ? array_values($rows) : null;
    }
}
