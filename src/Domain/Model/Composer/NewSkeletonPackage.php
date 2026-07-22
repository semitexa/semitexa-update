<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model\Composer;

/**
 * A semitexa/* package the current skeleton (semitexa/ultimate) requires but
 * the consumer project's composer.json does not declare — surfaced as an
 * advisory proposal, never auto-installed.
 */
final readonly class NewSkeletonPackage
{
    public function __construct(
        public string $name,
        public string $pinnedVersion,
        public string $description,
    ) {
    }
}
