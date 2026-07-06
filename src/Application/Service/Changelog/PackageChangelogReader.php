<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Changelog;

use Semitexa\Update\Application\Service\Packaging\Releases\Support\SemitexaReleaseVersion;
use Semitexa\Update\Domain\Model\Changelog\ReleaseNote;

/**
 * Reads per-package CHANGELOG.md files and slices them by version, so a
 * version delta ("semitexa/core 1.0.4 → 1.0.6") can be answered with the
 * human notes for every version in between.
 *
 * Convention (existing files already follow it, e.g. semitexa-tenancy):
 *
 *   # Changelog
 *   ## v1.0.6 — 2026-07-01        (date suffix optional, `v` optional)
 *   ...markdown body...
 *   ## v1.0.5
 *   ...
 *
 * `## Unreleased` is allowed on top and surfaces only in latestNotes().
 * Lookup covers both install layouts: vendor/semitexa/<name>/CHANGELOG.md
 * (consumer) and packages/semitexa-<name>/CHANGELOG.md (dev workspace).
 */
final class PackageChangelogReader
{
    public function __construct(
        private readonly string $projectRoot,
    ) {
    }

    /**
     * Notes for versions in (from, to], newest first. Null $from means
     * "everything up to and including $to".
     *
     * @return list<ReleaseNote>
     */
    public function notesBetween(string $package, ?string $from, string $to): array
    {
        $notes = [];
        foreach ($this->allNotes($package) as $note) {
            if (strcasecmp($note->version, 'Unreleased') === 0) {
                continue;
            }
            if ($this->compare($note->version, $to) > 0) {
                continue;
            }
            if ($from !== null && $this->compare($note->version, $from) <= 0) {
                continue;
            }
            $notes[] = $note;
        }
        return $notes;
    }

    /**
     * Newest sections regardless of range (includes Unreleased).
     *
     * @return list<ReleaseNote>
     */
    public function latestNotes(string $package, int $limit = 5): array
    {
        return array_slice($this->allNotes($package), 0, max(1, $limit));
    }

    /**
     * All sections in file order (files list newest first by convention).
     *
     * @return list<ReleaseNote>
     */
    public function allNotes(string $package): array
    {
        $path = $this->changelogPath($package);
        if ($path === null) {
            return [];
        }

        $markdown = @file_get_contents($path);
        if ($markdown === false) {
            return [];
        }

        return $this->parse($package, $markdown);
    }

    public function changelogPath(string $package): ?string
    {
        $short = str_starts_with($package, 'semitexa/') ? substr($package, strlen('semitexa/')) : null;
        if ($short === null) {
            return null;
        }

        $candidates = [
            $this->projectRoot . '/vendor/semitexa/' . $short . '/CHANGELOG.md',
            $this->projectRoot . '/packages/semitexa-' . $short . '/CHANGELOG.md',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @return list<ReleaseNote>
     */
    private function parse(string $package, string $markdown): array
    {
        $notes = [];
        $version = null;
        $date = null;
        $body = [];

        $flush = function () use (&$notes, &$version, &$date, &$body, $package): void {
            if ($version !== null) {
                $notes[] = new ReleaseNote($package, $version, $date, trim(implode("\n", $body)));
            }
            $version = null;
            $date = null;
            $body = [];
        };

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^##\s+(?!#)(.+)$/', $line, $m) === 1) {
                $flush();
                $heading = trim($m[1]);
                // "v1.0.6 — 2026-07-01" / "1.0.6 - 2026-07-01" / "v1.0.6"
                if (preg_match('/^v?(\S+?)(?:\s+[—–-]\s+(.+))?$/u', $heading, $h) === 1) {
                    $version = $h[1];
                    $date = isset($h[2]) && trim($h[2]) !== '' ? trim($h[2]) : null;
                } else {
                    $version = $heading;
                }
                continue;
            }
            if ($version !== null) {
                $body[] = $line;
            }
        }
        $flush();

        return $notes;
    }

    private function compare(string $left, string $right): int
    {
        if (SemitexaReleaseVersion::isValid($left) && SemitexaReleaseVersion::isValid($right)) {
            return SemitexaReleaseVersion::compare($left, $right);
        }
        return version_compare(ltrim($left, 'v'), ltrim($right, 'v'));
    }
}
