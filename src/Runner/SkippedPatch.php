<?php

declare(strict_types=1);

namespace Semitexa\Update\Runner;

/**
 * One patch that was gated out of a run by SchemaCompatibilityChecker. The runner
 * surfaces these in the RunReport so operators can see at a glance which patches
 * need an `orm:sync` (or a framework version bump) before they can apply.
 */
final readonly class SkippedPatch
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public string $identity,
        public array $reasons,
    ) {
    }
}
