<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

final readonly class SubscribeRequest
{
    public function __construct(
        public ?string $callbackUrl     = null,
        public ?string $externalOrderId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [];

        if ($this->callbackUrl !== null) {
            $data['callback_url'] = $this->callbackUrl;
        }

        if ($this->externalOrderId !== null) {
            $data['external_order_id'] = $this->externalOrderId;
        }

        return $data;
    }
}
