<?php

declare(strict_types=1);

namespace Bog\Payments\Enum;

enum OrderStatus: string
{
    case Created                  = 'created';
    case Processing               = 'processing';
    case Completed                = 'completed';
    case Rejected                 = 'rejected';
    case Refunded                 = 'refunded';
    case PartiallyRefunded        = 'partially_refunded';
    case PreAuthorizationBlocked  = 'pre_authorization_blocked';
    case PartiallyCompleted       = 'partially_completed';
}
