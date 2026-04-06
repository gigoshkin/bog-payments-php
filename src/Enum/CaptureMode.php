<?php

declare(strict_types=1);

namespace Bog\Payments\Enum;

enum CaptureMode: string
{
    case Automatic = 'automatic';
    case Manual    = 'manual';
}
