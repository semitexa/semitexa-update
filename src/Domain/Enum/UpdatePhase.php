<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

/**
 * Phases of the update lifecycle, expressed relative to the ORM schema sync:
 *   - Pre   : data fixups that must run before the schema changes
 *   - Apply : the bulk of data patches, run after the schema is in the target shape
 *   - Post  : finalization that depends on the Apply patches having completed
 *
 * The runner iterates this list in order; DAG ordering is applied within each phase.
 */
enum UpdatePhase: string
{
    case Pre   = 'pre';
    case Apply = 'apply';
    case Post  = 'post';

    /**
     * @return list<self>
     */
    public static function order(): array
    {
        return [self::Pre, self::Apply, self::Post];
    }
}
