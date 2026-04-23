<?php

declare(strict_types=1);

namespace Semitexa\Update\Exception;

final class DagCycleException extends UpdateException
{
    /**
     * @param list<class-string> $cycle Step FQCNs forming the cycle, in traversal order.
     */
    public function __construct(
        public readonly array $cycle,
    ) {
        parent::__construct(
            'Update-step dependency cycle detected: ' . implode(' -> ', $cycle)
        );
    }
}
