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

    /** Tries before calling Packagist unreachable — one blip must not decide an update. */
    private const FETCH_ATTEMPTS = 3;

    /** Multiplied by the attempt number, so waits grow: 0.25s, then 0.5s. */
    private const RETRY_BACKOFF_MICROSECONDS = 250_000;

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
            // Unreachable, not absent. Deliberately NOT cached: caching it
            // would turn one blip into "this package has no releases" for the
            // rest of the process, and the caller reports that to the operator
            // as missing Packagist metadata and blocks the update. A later call
            // gets to ask again.
            return [];
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

    /**
     * The body, or null when Packagist could not be asked.
     *
     * A 404 is an answer — the package genuinely has no metadata — and returns
     * an empty body rather than null, so the caller may cache it. Anything
     * else (timeout, DNS, connection reset, 5xx) is not an answer, and one
     * attempt is not enough to call it one: this used to make a single blip
     * indistinguishable from a missing package, which then blocked the update
     * with "no Packagist metadata" for a package that was on Packagist all
     * along.
     */
    private function fetch(string $url): ?string
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $this->timeoutSeconds,
                    'header' => "User-Agent: Semitexa-Update/PackagistVersionResolver\r\n",
                    // Needed to see the status line at all: without it a 404
                    // and a dead connection are both just `false`.
                    'ignore_errors' => true,
                ],
            ]);

            $http_response_header = [];
            $body = @file_get_contents($url, false, $ctx);
            $status = $this->statusFrom($http_response_header ?? []);

            if ($status === 404) {
                return '';
            }

            if ($body !== false && $status !== null && $status < 400) {
                return $body;
            }

            if ($attempts >= self::FETCH_ATTEMPTS) {
                return null;
            }

            usleep(self::RETRY_BACKOFF_MICROSECONDS * $attempts);
        }
    }

    /**
     * @param list<string> $headers
     */
    private function statusFrom(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status ?? null;
    }
}
