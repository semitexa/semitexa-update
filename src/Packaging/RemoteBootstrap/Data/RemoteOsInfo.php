<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\RemoteBootstrap\Data;

final readonly class RemoteOsInfo
{
    public function __construct(
        public string $id,
        public string $versionId,
        public ?string $prettyName,
    ) {}
}
