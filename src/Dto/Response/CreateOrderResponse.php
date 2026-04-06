<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

final readonly class CreateOrderResponse
{
    public function __construct(
        public string  $orderId,
        public string  $redirectUrl,
        public ?string $detailsUrl = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id'])) {
            throw new \InvalidArgumentException('CreateOrderResponse: missing required field "id".');
        }

        $redirectUrl = $data['_links']['redirect']['href']
            ?? $data['redirect_url']
            ?? null;

        if ($redirectUrl === null) {
            throw new \InvalidArgumentException('CreateOrderResponse: missing redirect URL in response.');
        }

        return new self(
            orderId:    (string) $data['id'],
            redirectUrl: (string) $redirectUrl,
            detailsUrl:  isset($data['_links']['details']['href'])
                ? (string) $data['_links']['details']['href']
                : null,
        );
    }
}
