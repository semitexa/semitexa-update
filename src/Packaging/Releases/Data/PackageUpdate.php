<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\Releases\Data;

final readonly class PackageUpdate
{
    public function __construct(
        public string $packageName,
        public string $installedVersion,
        public string $latestVersion,
        public string $source,
    ) {}
}
