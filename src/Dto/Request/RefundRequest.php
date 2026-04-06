<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

final readonly class RefundRequest
{
    /**
     * @param float|null $amount Partial refund amount. Null triggers a full refund.
     */
    public function __construct(
        public ?float $amount = null,
    ) {
        if ($this->amount !== null && $this->amount <= 0) {
            throw new \InvalidArgumentException('RefundRequest: amount must be > 0 when provided.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->amount === null) {
            return [];
        }

        return ['amount' => $this->amount];
    }
}
