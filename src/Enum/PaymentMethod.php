<?php

declare(strict_types=1);

namespace Bog\Payments\Enum;

enum PaymentMethod: string
{
    case Card       = 'card';
    case GooglePay  = 'google_pay';
    case ApplePay   = 'apple_pay';
    case BogP2P     = 'bog_p2p';
    case BogLoyalty = 'bog_loyalty';
    case Bnpl       = 'bnpl';
    case BogLoan    = 'bog_loan';
    case GiftCard   = 'gift_card';
}
