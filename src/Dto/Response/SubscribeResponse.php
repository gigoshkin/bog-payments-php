<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

final readonly class SubscribeResponse
{
    public function __construct(
        public string  $orderId,
        public ?string $detailsUrl = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id'])) {
            throw new \InvalidArgumentException('SubscribeResponse: missing required field "id".');
        }

        return new self(
            orderId:    (string) $data['id'],
            detailsUrl: isset($data['_links']['details']['href'])
                ? (string) $data['_links']['details']['href']
                : null,
        );
    }
}
