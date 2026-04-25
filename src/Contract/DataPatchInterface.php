<?php

declare(strict_types=1);

namespace Semitexa\Update\Contract;

use Semitexa\Update\Context\DataPatchContext;

interface DataPatchInterface
{
    /**
     * Apply the data patch. Must be idempotent — the runner may re-enter after a
     * crash, and the patch body is responsible for its own guards (check-then-act,
     * unique-keys, IF NOT EXISTS data filters). The journal prevents already-applied
     * patches from running, but not an interrupted patch from being re-entered on recovery.
     *
     * Patch bodies must operate on existing data only. Schema mutations (CREATE TABLE,
     * ALTER TABLE, DROP TABLE, RENAME, TRUNCATE) belong to the ORM and are rejected by
     * the runner's safety guard.
     */
    public function apply(DataPatchContext $ctx): void;
}
