<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\PackageDrift;

use Semitexa\Update\Domain\Enum\PackageDriftStatus;

/**
 * Drift state of a single Composer package, computed from three local sources:
 *
 *   declared   — constraint or pin from composer.json (require / require-dev)
 *   locked     — concrete version from composer.lock
 *   installed  — concrete version from vendor/composer/installed.json
 *
 * `upstream` is reserved for the future --check-upstream extension and is
 * null in the offline-default path. The renderer must clearly label which
 * column each value came from — these are NOT the same thing as a "latest
 * available release", which only an upstream source can know.
 */
final readonly class PackageDriftEntry
{
    public function __construct(
        public string $name,
        public ?string $declared,
        public ?string $locked,
        public ?string $installed,
        public ?string $upstream,
        public PackageDriftStatus $status,
        public string $actionHint,
    ) {
    }
}
