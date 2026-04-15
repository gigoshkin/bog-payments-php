<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

final readonly class CreateOrderResponse
{
    /**
     * @param string      $orderId     BOG order ID.
     * @param string|null $redirectUrl Customer redirect URL. Null for external Apple Pay orders
     *                                 (use $acceptUrl instead) and for external Google Pay orders
     *                                 that complete without 3DS.
     * @param string|null $detailsUrl  Receipt/polling URL.
     * @param string|null $acceptUrl   Apple Pay accept URL. After creating an order with
     *                                 ExternalApplePayConfig, POST the apple_pay_token to this URL
     *                                 via BogClient::completeApplePayPayment().
     * @param string|null $status      Immediate order status. Set for external payments that
     *                                 complete synchronously (e.g. Google Pay without 3DS).
     */
    public function __construct(
        public string  $orderId,
        public ?string $redirectUrl = null,
        public ?string $detailsUrl  = null,
        public ?string $acceptUrl   = null,
        public ?string $status      = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id'])) {
            throw new \InvalidArgumentException('CreateOrderResponse: missing required field "id".');
        }

        $redirectUrl = isset($data['_links']['redirect']['href'])
            ? (string) $data['_links']['redirect']['href']
            : (isset($data['redirect_url']) ? (string) $data['redirect_url'] : null);

        $acceptUrl  = isset($data['_links']['accept']['href'])
            ? (string) $data['_links']['accept']['href']
            : null;

        $detailsUrl = isset($data['_links']['details']['href'])
            ? (string) $data['_links']['details']['href']
            : null;

        $status = isset($data['status']) ? (string) $data['status'] : null;

        return new self(
            orderId:     (string) $data['id'],
            redirectUrl: $redirectUrl,
            detailsUrl:  $detailsUrl,
            acceptUrl:   $acceptUrl,
            status:      $status,
        );
    }
}
