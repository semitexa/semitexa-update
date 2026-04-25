<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\RemoteBootstrap\Data;

final readonly class RemoteDeployTarget
{
    public function __construct(
        public string $user,
        public string $host,
    ) {}

    public function toConnectionString(): string
    {
        return $this->user . '@' . $this->host;
    }
}
