<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Changelog;

/**
 * One version section of a package CHANGELOG.md: the human release notes
 * behind a version delta the update system reports.
 */
final readonly class ReleaseNote
{
    public function __construct(
        public string $package,
        public string $version,
        public ?string $date,
        public string $body,
    ) {
    }
}
