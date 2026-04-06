<?php

declare(strict_types=1);

namespace Bog\Payments\Enum;

enum ActionType: string
{
    case Authorize = 'authorize';
    case Capture   = 'capture';
    case Refund    = 'refund';
    case Cancel    = 'cancel';
}
