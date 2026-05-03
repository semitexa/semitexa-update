<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

final readonly class RemotePathState
{
    public function __construct(
        public string $path,
        public bool $exists,
        public bool $hasFiles,
        public bool $hasMarker,
        public bool $hasComposeFile,
    ) {}

    public function isInitialized(): bool
    {
        return $this->hasMarker || $this->hasComposeFile || ($this->exists && $this->hasFiles);
    }
}
