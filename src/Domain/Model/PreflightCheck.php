<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

/**
 * One preflight verification (database reachable, disk space, …) with a
 * human-actionable message when it fails.
 */
final readonly class PreflightCheck
{
    public function __construct(
        public string $name,
        public bool $ok,
        public string $message,
    ) {
    }
}
