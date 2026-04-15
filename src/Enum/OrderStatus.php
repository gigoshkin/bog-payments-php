<?php

declare(strict_types=1);

namespace Bog\Payments\Enum;

enum OrderStatus: string
{
    case Created                 = 'created';
    case Processing              = 'processing';
    case Completed               = 'completed';
    case Rejected                = 'rejected';
    case RefundRequested         = 'refund_requested';
    case Refunded                = 'refunded';
    case PartiallyRefunded       = 'refunded_partially';
    case AuthRequested           = 'auth_requested';
    case PreAuthorizationBlocked = 'blocked';
    case PartiallyCompleted      = 'partial_completed';
}
