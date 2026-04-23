<?php

declare(strict_types=1);

namespace Semitexa\Update\Exception;

use Throwable;

final class StepExecutionException extends UpdateException
{
    public function __construct(
        public readonly string $stepFqcn,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Step %s failed: %s', $stepFqcn, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
