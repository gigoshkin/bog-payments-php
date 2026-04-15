<?php

declare(strict_types=1);

namespace Bog\Payments\Enum;

enum ActionType: string
{
    case Authorize        = 'authorize';
    case PartialAuthorize = 'partial_authorize';
    case CancelAuthorize  = 'cancel_authorize';
    case Capture          = 'capture';
    case Refund           = 'refund';
    case PartialRefund    = 'partial_refund';
    case Cancel           = 'cancel';
}
