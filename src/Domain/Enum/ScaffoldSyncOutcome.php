<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * Per-entry result of {@see \Semitexa\Update\Application\Service\Scaffold\ScaffoldSyncEngine::execute()}.
 */
enum ScaffoldSyncOutcome: string
{
    case NoOp = 'no_op';
    case Applied = 'applied';
    case WouldApply = 'would_apply';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
