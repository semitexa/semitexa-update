<?php

declare(strict_types=1);

namespace Semitexa\Update\Domain\Enum;

enum PatchStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed  = 'failed';
}
