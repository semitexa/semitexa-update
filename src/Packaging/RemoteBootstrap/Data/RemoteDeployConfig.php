<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\RemoteBootstrap\Data;

final readonly class RemoteDeployConfig
{
    /**
     * @param list<RemoteDeployTarget> $targets
     */
    public function __construct(
        public array $targets,
        public string $deployPath,
        public int $sshPort,
        public ?string $domain,
        public bool $preferPasswordAuth,
    ) {}
}
