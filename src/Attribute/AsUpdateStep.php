<?php

declare(strict_types=1);

namespace Semitexa\Update\Attribute;

use Attribute;
use Semitexa\Update\Enum\UpdatePhase;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsUpdateStep
{
    /**
     * @param UpdatePhase           $phase         Execution phase. Runner evaluates phases in strict order.
     * @param list<class-string>    $dependencies  Other step classes that must apply before this one.
     *                                             Used to build the per-phase DAG.
     * @param string|null           $description   Operator-facing one-line summary.
     */
    public function __construct(
        public UpdatePhase $phase = UpdatePhase::Deploy,
        public array $dependencies = [],
        public ?string $description = null,
    ) {
    }
}
