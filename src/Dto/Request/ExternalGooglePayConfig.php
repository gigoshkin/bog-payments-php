<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

/**
 * Configuration for accepting Google Pay payments on the merchant's own webpage
 * (as opposed to Bank of Georgia's hosted payment page).
 *
 * The merchant must add the Google Pay button to their own website and obtain
 * the encrypted token from the Google Pay JS SDK, then pass it here.
 *
 * @see https://api.bog.ge/docs/en/payments/external-orders/external-googlepay
 */
final readonly class ExternalGooglePayConfig
{
    /**
     * @param string $googlePayToken Full encrypted token string returned by the Google Pay SDK.
     *                               Must include all nested fields exactly as received — do not
     *                               modify or truncate.
     */
    public function __construct(
        public string $googlePayToken,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'external'         => true,
            'google_pay_token' => $this->googlePayToken,
        ];
    }
}
