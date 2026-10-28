<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Model;

final readonly class RemoteDeployTarget
{
    public function __construct(
        public string $user,
        public string $host,
    ) {}

    /** user@host, an IPv6 address in brackets: what the settings hold and scp takes. */
    public function toConnectionString(): string
    {
        return $this->user . '@' . (str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host);
    }

    /** user@host as ssh takes it: an IPv6 address bare. */
    public function sshDestination(): string
    {
        return $this->user . '@' . $this->host;
    }
}
