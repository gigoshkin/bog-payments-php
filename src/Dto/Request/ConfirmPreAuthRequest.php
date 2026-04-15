<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

final readonly class ConfirmPreAuthRequest
{
    /**
     * @param float|null  $amount      Amount to confirm. Null = full pre-authorized amount. Partial amount = partial confirmation.
     * @param string|null $description Reason for confirmation.
     */
    public function __construct(
        public ?float  $amount      = null,
        public ?string $description = null,
    ) {
        if ($this->amount !== null && $this->amount <= 0) {
            throw new \InvalidArgumentException('ConfirmPreAuthRequest: amount must be > 0 when provided.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [];

        if ($this->amount !== null) {
            $data['amount'] = $this->amount;
        }

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        return $data;
    }
}
