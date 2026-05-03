<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Packaging\Releases\Support;

final class SemitexaReleaseVersion
{
    public static function isValid(string $version): bool
    {
        return self::parseDateBased($version) !== null || self::parseSemantic($version) !== null;
    }

    public static function isStable(string $version): bool
    {
        return self::isValid($version) && !str_contains($version, '-');
    }

    public static function compare(string $left, string $right): int
    {
        $leftDate = self::parseDateBased($left);
        $rightDate = self::parseDateBased($right);
        $leftSemantic = self::parseSemantic($left);
        $rightSemantic = self::parseSemantic($right);

        if ($leftDate !== null && $rightDate !== null) {
            $baseComparison = strcmp($leftDate['base'], $rightDate['base']);
            if ($baseComparison !== 0) {
                return $baseComparison;
            }

            return self::suffixRank($leftDate['suffix']) <=> self::suffixRank($rightDate['suffix']);
        }

        if ($leftSemantic !== null && $rightSemantic !== null) {
            foreach (['major', 'minor', 'patch'] as $part) {
                $comparison = $leftSemantic[$part] <=> $rightSemantic[$part];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return self::suffixRank($leftSemantic['suffix']) <=> self::suffixRank($rightSemantic['suffix']);
        }

        if (($leftDate !== null && $rightSemantic !== null) || ($leftSemantic !== null && $rightDate !== null)) {
            return self::schemeRank($leftSemantic !== null) <=> self::schemeRank($rightSemantic !== null);
        }

        return version_compare($left, $right);
    }

    /**
     * @param list<string> $versions
     */
    public static function latestStable(array $versions): ?string
    {
        $stableVersions = array_values(array_filter($versions, self::isStable(...)));
        if ($stableVersions === []) {
            return null;
        }

        usort($stableVersions, self::compare(...));
        return array_pop($stableVersions) ?: null;
    }

    /**
     * @return array{base: string, suffix: string}|null
     */
    private static function parseDateBased(string $version): ?array
    {
        $version = trim($version);
        if (preg_match('/^(\d{4}\.\d{2}\.\d{2}\.\d{4})(?:-(alpha|beta|rc\d+|p\d+))?$/i', $version, $m) !== 1) {
            return null;
        }

        return [
            'base' => $m[1],
            'suffix' => strtolower($m[2] ?? ''),
        ];
    }

    /**
     * @return array{major: int, minor: int, patch: int, suffix: string}|null
     */
    private static function parseSemantic(string $version): ?array
    {
        $version = trim($version);
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-(alpha|beta|rc\d+|p\d+))?$/i', $version, $m) !== 1) {
            return null;
        }

        return [
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'patch' => (int) $m[3],
            'suffix' => strtolower($m[4] ?? ''),
        ];
    }

    private static function suffixRank(string $suffix): int
    {
        if ($suffix === '') {
            return 4000;
        }

        if (preg_match('/^p(\d+)$/', $suffix, $m) === 1) {
            return 5000 + (int) $m[1];
        }

        if (preg_match('/^rc(\d+)$/', $suffix, $m) === 1) {
            return 3000 + (int) $m[1];
        }

        return match ($suffix) {
            'beta' => 2000,
            'alpha' => 1000,
            default => 0,
        };
    }

    private static function schemeRank(bool $semantic): int
    {
        return $semantic ? 2 : 1;
    }
}
