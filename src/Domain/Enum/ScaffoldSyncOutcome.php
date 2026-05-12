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

    /**
     * Surfaced when the live file is left untouched, no .new candidate was
     * written, and the operator must decide what to do. This is the default
     * for LocallyModified entries unless the operator opts in via
     * --write-scaffold-candidates.
     */
    case ManualReview = 'manual_review';
}
