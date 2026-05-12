<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;

/**
 * Reads `https://repo.packagist.org/p2/<pkg>.json` to determine the latest
 * stable Semitexa release tag for a package.
 *
 * Stable = `YYYY.MM.DD.HHMM` with no `-beta` / `-alpha` / `-rc` suffix.
 * dev-* refs are ignored. Network errors return null; the caller decides
 * how to handle that (typically: skip the package, leave its pin alone).
 *
 * Per-process cache so a single command call doesn't probe the same URL
 * 27 times across the semitexa/* set.
 */
final class PackagistVersionResolver implements UpstreamVersionResolverInterface
{
    private const ENDPOINT = 'https://repo.packagist.org/p2/%s.json';

    /**
     * @var array<string, list<string>>
     */
    private array $cache = [];

    public function __construct(
        private readonly int $timeoutSeconds = 8,
    ) {
    }

    public function latestStable(string $package): ?string
    {
        $versions = $this->stableVersions($package);
        if ($versions === []) {
            return null;
        }
        return SemitexaReleaseVersion::latestStable($versions);
    }

    public function hasVersion(string $package, string $version): bool
    {
        return in_array($version, $this->stableVersions($package), true);
    }

    /**
     * @return list<string>
     */
    private function stableVersions(string $package): array
    {
        if (isset($this->cache[$package])) {
            return $this->cache[$package];
        }
        $url = sprintf(self::ENDPOINT, $package);
        $body = $this->fetch($url);
        if ($body === null) {
            return $this->cache[$package] = [];
        }
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->cache[$package] = [];
        }
        if (!is_array($data) || !isset($data['packages'][$package])) {
            return $this->cache[$package] = [];
        }
        $versions = [];
        foreach ($data['packages'][$package] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = (string) ($row['version'] ?? '');
            // The Semitexa update workflow only consumes UTC date-based
            // releases (YYYY.MM.DD.HHMM). Legacy semver tags (0.x / 1.x.y)
            // are real on Packagist for some packages but predate the
            // current release convention; bumping a current YYYY.MM.DD.HHMM
            // pin down to a 1.0.x tag would be a regression.
            if (preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $v) !== 1) {
                continue;
            }
            $versions[] = $v;
        }
        return $this->cache[$package] = $versions;
    }

    private function fetch(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "User-Agent: Semitexa-Update/PackagistVersionResolver\r\n",
                'ignore_errors' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? $body : null;
    }
}
