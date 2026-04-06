<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

final readonly class BasketItem
{
    public function __construct(
        public string  $productId,
        public int     $quantity,
        public float   $unitPrice,
        public ?string $description = null,
    ) {
        if ($this->quantity <= 0) {
            throw new \InvalidArgumentException('BasketItem: quantity must be > 0.');
        }
        if ($this->unitPrice < 0) {
            throw new \InvalidArgumentException('BasketItem: unitPrice must be >= 0.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'product_id' => $this->productId,
            'quantity'   => $this->quantity,
            'unit_price' => $this->unitPrice,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        return $data;
    }
}
