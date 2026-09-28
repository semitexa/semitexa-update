<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

/**
 * Reads `https://repo.packagist.org/p2/<pkg>.json`: the stable Semitexa
 * release tags of a package, and what a given release requires.
 *
 * Stable = `YYYY.MM.DD.HHMM` with no `-beta` / `-alpha` / `-rc` suffix.
 * dev-* refs are ignored. Network errors return null, a 404 returns an
 * empty answer — see UpstreamVersionResolverInterface for why they differ.
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
     * Expanded p2 rows per package; [] when Packagist answered with nothing.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $rows = [];

    /** @var \Closure(string): ?string */
    private readonly \Closure $fetchBody;

    /**
     * @param (\Closure(string): ?string)|null $fetchBody Test seam: URL → body, '' for a 404, null when unreachable.
     */
    public function __construct(
        private readonly int $timeoutSeconds = 8,
        ?\Closure $fetchBody = null,
    ) {
        $this->fetchBody = $fetchBody ?? $this->fetch(...);
    }

    public function stableVersions(string $package): ?array
    {
        $rows = $this->rows($package);
        if ($rows === null) {
            return null;
        }

        $versions = [];
        foreach ($rows as $row) {
            $v = $row['version'] ?? null;
            if (!is_string($v)) {
                continue;
            }
            // The Semitexa update workflow only consumes UTC date-based
            // releases (YYYY.MM.DD.HHMM). Legacy semver tags (0.x / 1.x.y)
            // are real on Packagist for some packages but predate the
            // current release convention; bumping a current YYYY.MM.DD.HHMM
            // pin down to a 1.0.x tag would be a regression.
            if (preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{4}$/', $v) === 1) {
                $versions[] = $v;
            }
        }

        return $versions;
    }

    public function requiresOf(string $package, string $version): ?array
    {
        foreach ($this->rows($package) ?? [] as $row) {
            if (($row['version'] ?? null) !== $version) {
                continue;
            }
            $require = $row['require'] ?? null;
            if (!is_array($require)) {
                return [];
            }
            $out = [];
            foreach ($require as $name => $constraint) {
                if (is_string($name) && is_string($constraint)) {
                    $out[$name] = $constraint;
                }
            }

            return $out;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function rows(string $package): ?array
    {
        if (isset($this->rows[$package])) {
            return $this->rows[$package];
        }
        $body = ($this->fetchBody)(sprintf(self::ENDPOINT, $package));
        if ($body === null) {
            // Unreachable, not absent. Deliberately NOT cached: caching it
            // would turn one blip into "this package has no releases" for the
            // rest of the process. A later call gets to ask again.
            return null;
        }
        if ($body === '') {
            // A 404: Packagist answered, and the package is not published there.
            return $this->rows[$package] = [];
        }
        // Anything else that is not the package's metadata — a truncated body,
        // a proxy's error page served with 200 — is not an answer either. Read
        // as "absent", it let the planner leave an exact pin alone and carry
        // on; it must block the way an unreachable registry does.
        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $raw = is_array($data) ? ($data['packages'][$package] ?? null) : null;
        if (!is_array($raw)) {
            return null;
        }

        $typed = [];
        foreach ($raw as $row) {
            if (is_array($row)) {
                $typed[] = array_filter($row, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        // p2 is minified: only the first row is complete. Reading `require`
        // from a later row without expanding it yields stale or missing data.
        return $this->rows[$package] = P2MetadataExpander::expand($typed);
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
