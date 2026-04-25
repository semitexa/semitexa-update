<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\RemoteBootstrap\Data;

final readonly class SshCommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }
}
