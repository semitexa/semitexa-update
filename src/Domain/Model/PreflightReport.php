<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

/**
 * Result of the read-only preflight stage that runs before any mutating
 * update stage. Any failed check aborts the run before composer, scaffold,
 * or the database are touched.
 */
final readonly class PreflightReport
{
    /**
     * @param list<PreflightCheck> $checks
     */
    public function __construct(
        public array $checks,
    ) {
    }

    public function isSuccess(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<PreflightCheck>
     */
    public function failedChecks(): array
    {
        return array_values(array_filter($this->checks, static fn (PreflightCheck $c): bool => !$c->ok));
    }
}
