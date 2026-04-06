<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

final readonly class SplitTransfer
{
    public function __construct(
        public string  $iban,
        public float   $amount,
        public ?string $externalOrderId = null,
    ) {
        if ($this->amount <= 0) {
            throw new \InvalidArgumentException('SplitTransfer: amount must be > 0.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'iban'   => $this->iban,
            'amount' => $this->amount,
        ];

        if ($this->externalOrderId !== null) {
            $data['external_order_id'] = $this->externalOrderId;
        }

        return $data;
    }
}
