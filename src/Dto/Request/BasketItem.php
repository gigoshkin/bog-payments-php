<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

final readonly class BasketItem
{
    public function __construct(
        public string  $productId,
        public int     $quantity,
        public float   $unitPrice,
        public ?string $description       = null,
        public ?float  $unitDiscountPrice = null,
        public ?float  $vat               = null,
        public ?float  $vatPercent        = null,
        public ?float  $totalPrice        = null,
        public ?string $image             = null,
        public ?string $packageCode       = null,
        public ?string $tin               = null,
        public ?string $pinfl             = null,
        public ?string $productDiscountId = null,
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
        if ($this->unitDiscountPrice !== null) {
            $data['unit_discount_price'] = $this->unitDiscountPrice;
        }
        if ($this->vat !== null) {
            $data['vat'] = $this->vat;
        }
        if ($this->vatPercent !== null) {
            $data['vat_percent'] = $this->vatPercent;
        }
        if ($this->totalPrice !== null) {
            $data['total_price'] = $this->totalPrice;
        }
        if ($this->image !== null) {
            $data['image'] = $this->image;
        }
        if ($this->packageCode !== null) {
            $data['package_code'] = $this->packageCode;
        }
        if ($this->tin !== null) {
            $data['tin'] = $this->tin;
        }
        if ($this->pinfl !== null) {
            $data['pinfl'] = $this->pinfl;
        }
        if ($this->productDiscountId !== null) {
            $data['product_discount_id'] = $this->productDiscountId;
        }

        return $data;
    }
}
