<?php

declare(strict_types=1);

namespace Semitexa\Update\Schema;

/**
 * Outcome of SchemaCompatibilityChecker for a single patch.
 *
 * `compatible = true` is the only state in which the runner may execute the patch.
 * The reasons list explains every gate that failed when incompatible — useful for
 * batched diagnostics in `update:plan`.
 */
final readonly class CompatibilityResult
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public bool $compatible,
        public array $reasons,
    ) {
    }

    public static function compatible(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $reasons
     */
    public static function incompatible(array $reasons): self
    {
        return new self(false, $reasons);
    }
}
