<?php

declare(strict_types=1);

namespace Bog\Payments\Enum;

/**
 * Numeric response codes returned by BOG in action history.
 *
 * @see https://api.bog.ge/docs/en/payments/response-codes
 */
enum PaymentStatusCode: int
{
    // Success
    case SuccessfulPayment         = 100;
    case SuccessfulPreauthorization = 200;

    // Declined payments (101–112, 122, 199)
    case CardUsageRestrictions            = 101;
    case SavedCardNotFound                = 102;
    case InvalidCardDetails               = 103;
    case TransactionQuantityLimitExceeded = 104;
    case ExpiredCard                      = 105;
    case AmountLimitExceeded              = 106;
    case InsufficientFunds                = 107;
    case AuthenticationFailed             = 108;
    case TechnicalMalfunction             = 109;
    case TransactionTimeExpired           = 110;
    case AuthenticationTimeout            = 111;
    case GeneralError                     = 112;
    case AcquirerDeclined                 = 122;
    case UnidentifiableResponse           = 199;

    // Refund failures (161–169, 179)
    case RefundFailedContactBank1        = 161;
    case RefundDeclinedByIssuingBank     = 162;
    case RefundInsufficientFunds         = 163;
    case RefundFailedContactBank2        = 164;
    case RefundFailedContactBank3        = 165;
    case RefundFailedContactBank4        = 166;
    case RefundCardExpired               = 167;
    case RefundFailedContactBank5        = 168;
    case RefundCardExpiredOrInvalidDetails = 169;
    case UnknownResponse                 = 179;

    public function isSuccess(): bool
    {
        return match ($this) {
            self::SuccessfulPayment, self::SuccessfulPreauthorization => true,
            default                                                    => false,
        };
    }

    public function isRefundFailure(): bool
    {
        return $this->value >= 161 && $this->value <= 179;
    }

    public function isDeclined(): bool
    {
        return $this->value >= 101 && $this->value <= 122;
    }
}
