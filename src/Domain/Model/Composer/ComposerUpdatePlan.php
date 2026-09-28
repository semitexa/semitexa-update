<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Composer;

/**
 * Plan for the Composer-update phase.
 *
 * `releaseSetVersion` is the `semitexa/ultimate` release the targets were read
 * from — null when that release could not be read, in which case each pin
 * targets its own latest release. Ultimate is the carrier because every
 * release cut republishes it with the exact version of every package, tested
 * together; no other package is re-tagged every time, so none can stand in
 * for the set.
 *
 * `releaseSetUnreachable` is true when the registry could not be asked for
 * that release. It blocks the phase on its own: a project whose pins are all
 * "*" has no exact pin to mark unresolved, and would otherwise be reported
 * clean with nothing known about upstream.
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
        public ?string $releaseSetVersion,
        public string $composerCommand,
        public bool $inContainer,
        public string $containerError,
        public bool $releaseSetUnreachable = false,
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
