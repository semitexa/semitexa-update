<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\RemoteBootstrap\Data;

final readonly class RemoteDeployArtifact
{
    public function __construct(
        public string $path,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
