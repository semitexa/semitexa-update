<?php

declare(strict_types=1);

namespace Semitexa\Update\Exception;

use Throwable;

final class PatchExecutionException extends UpdateException
{
    public function __construct(
        public readonly string $patchIdentity,
        public readonly string $patchFqcn,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf('Data patch %s (%s) failed: %s', $patchIdentity, $patchFqcn, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
