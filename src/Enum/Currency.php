<?php

declare(strict_types=1);

namespace Bog\Payments\Enum;

enum Currency: string
{
    case GEL = 'GEL';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
}
