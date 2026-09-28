<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\PackageDrift;

/**
 * Aggregate result of PackageDriftInspector.
 *
 * `entries` is one record per inspected package. `releaseSetCoherent` is a
 * SET-level fact: whether the installed `semitexa/*` packages share one
 * YYYY.MM.DD release date. It is informational — a release cut tags only the
 * packages that changed, so an up-to-date project normally spans several.
 *
 * `mixedReleaseDates` lists the distinct YYYY.MM.DD dates seen across the
 * installed semitexa/* set (empty when the set is coherent or empty).
 *
 * The report carries no `latest_available` field. Such a concept requires an
 * upstream probe and only appears when the (future) --check-upstream flag is
 * used; in this default mode the report is offline-derivable from local files.
 */
final readonly class PackageDriftReport
{
    /**
     * @param list<PackageDriftEntry> $entries
     * @param list<string>            $mixedReleaseDates
     */
    public function __construct(
        public array $entries,
        public bool $releaseSetCoherent,
        public array $mixedReleaseDates,
    ) {
    }

    public function hasActionableDrift(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->status->isActionable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<PackageDriftEntry>
     */
    public function actionableEntries(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (PackageDriftEntry $e): bool => $e->status->isActionable(),
        ));
    }

    public function entryByName(string $name): ?PackageDriftEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }
        return null;
    }
}
