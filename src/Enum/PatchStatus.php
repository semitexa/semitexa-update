<?php

declare(strict_types=1);

namespace Semitexa\Update\Enum;

enum PatchStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed  = 'failed';
}
