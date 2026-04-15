<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

/**
 * Configuration for accepting Apple Pay payments on the merchant's own webpage
 * (as opposed to Bank of Georgia's hosted payment page).
 *
 * The merchant must obtain the Apple Pay certificate from Bank of Georgia, add it to
 * their domain, register the domain via ecommercemerchants@bog.ge, and implement the
 * Apple Pay JS SDK. After creating the order with this config, call
 * BogClient::completeApplePayPayment() with the token from the Apple Pay SDK.
 *
 * @see https://api.bog.ge/docs/en/payments/external-orders/external-applepay
 * @see https://api.bog.ge/docs/en/payments/external-orders/complete-external-applepay
 */
final readonly class ExternalApplePayConfig
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['external' => true];
    }
}
