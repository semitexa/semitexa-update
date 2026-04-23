<?php

declare(strict_types=1);

namespace Semitexa\Update\Contract;

use Semitexa\Update\Context\UpdateContext;

interface UpdateStepInterface
{
    /**
     * Apply the step. Must be idempotent — the runner may re-enter after a
     * crash, and the step body is responsible for its own guards (IF NOT
     * EXISTS / check-then-act). The journal prevents already-applied steps
     * from running, but not an interrupted step from being re-entered on
     * recovery.
     */
    public function apply(UpdateContext $ctx): void;
}
