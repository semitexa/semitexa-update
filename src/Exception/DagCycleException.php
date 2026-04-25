<?php

declare(strict_types=1);

namespace Semitexa\Update\Exception;

final class DagCycleException extends UpdateException
{
    /**
     * @param list<string> $cycle Patch identities ("module:id") forming the cycle, in traversal order.
     */
    public function __construct(
        public readonly array $cycle,
    ) {
        parent::__construct(
            'Data-patch dependency cycle detected: ' . implode(' -> ', $cycle)
        );
    }
}
