<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Composer;

/**
 * Plan for the Composer-update phase. `targetReleaseSet` is the anchor
 * version the runner chose to align release-pinned semitexa/* packages to —
 * typically the latest published `semitexa/update` tag.
 *
 * `composerCommand` is the exact command line the runner intends to execute
 * inside the container after pin rewrites complete.
 */
final readonly class ComposerUpdatePlan
{
    /**
     * @param list<ComposerUpdatePlanEntry> $entries
     */
    public function __construct(
        public array $entries,
        public ?string $targetReleaseSet,
        public string $composerCommand,
        public bool $inContainer,
        public string $containerError,
    ) {
    }

    public function entryByName(string $name): ?ComposerUpdatePlanEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * @return list<ComposerUpdatePlanEntry>
     */
    public function entriesToBump(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ComposerUpdatePlanEntry $e) => $e->willBeBumped(),
        ));
    }

    /**
     * @return list<ComposerUpdatePlanEntry>
     */
    public function skippedEntries(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ComposerUpdatePlanEntry $e) => $e->skipReason !== '',
        ));
    }

    /**
     * Exact-pinned packages whose upstream version could not be resolved.
     * Non-empty here is a blocking condition by default (unless the operator
     * passes --allow-partial-composer-update).
     *
     * @return list<ComposerUpdatePlanEntry>
     */
    public function unresolvedEntries(): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ComposerUpdatePlanEntry $e) => $e->isUnresolved(),
        ));
    }

    public function hasUnresolvedEntries(): bool
    {
        return $this->unresolvedEntries() !== [];
    }
}
