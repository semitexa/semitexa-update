<?php

declare(strict_types=1);

namespace Semitexa\Update\Enum;

enum UpdatePhase: string
{
    case PreDeploy = 'pre-deploy';
    case Deploy    = 'deploy';
    case Finalize  = 'finalize';

    /**
     * Phases in strict execution order. The runner iterates this list;
     * DAG ordering is applied within each phase.
     *
     * @return list<self>
     */
    public static function order(): array
    {
        return [self::PreDeploy, self::Deploy, self::Finalize];
    }
}
