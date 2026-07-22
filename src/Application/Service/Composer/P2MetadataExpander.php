<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service\Composer;

/**
 * Expands Composer p2 "minified" package metadata (composer/2.0): the first
 * row is complete, every following row carries only the fields that differ
 * from the previous row, and the literal string "__unset" removes a field.
 * Reading `require` (or any field) from an arbitrary row without expansion
 * silently yields stale/missing data.
 */
final class P2MetadataExpander
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function expand(array $rows): array
    {
        $expanded = [];
        $current = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $field => $value) {
                if ($value === '__unset') {
                    unset($current[$field]);
                    continue;
                }
                $current[$field] = $value;
            }
            $expanded[] = $current;
        }

        return $expanded;
    }
}
