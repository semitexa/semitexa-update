<?php

declare(strict_types=1);

namespace Semitexa\Update\Packaging\Releases\Data;

final readonly class DeploymentConfig
{
    public function __construct(
        public bool $enabled,
        public string $channel,
        public string $sourceMode,
        public ?string $healthcheckUrl,
        public ?string $privateRepositoryUrl,
        public ?string $restartCommand,
    ) {}
}
