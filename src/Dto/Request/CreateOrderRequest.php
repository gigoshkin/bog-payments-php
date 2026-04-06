<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

use Bog\Payments\Enum\CaptureMode;
use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\PaymentMethod;

final readonly class CreateOrderRequest
{
    /**
     * @param BasketItem[]    $basket          At least one item required.
     * @param PaymentMethod[] $paymentMethods  Restrict to these methods. Empty = all allowed.
     * @param SplitTransfer[] $splitTransfers  GEL-only multi-IBAN routing (max 10).
     */
    public function __construct(
        public string      $callbackUrl,
        public float       $totalAmount,
        public array       $basket,
        public Currency    $currency       = Currency::GEL,
        public CaptureMode $capture        = CaptureMode::Automatic,
        public ?string     $externalOrderId = null,
        public ?string     $redirectUrl    = null,
        public ?int        $ttl            = null,
        public array       $paymentMethods  = [],
        public array       $splitTransfers  = [],
        public bool        $saveCard        = false,
    ) {
        if ($this->totalAmount <= 0) {
            throw new \InvalidArgumentException('CreateOrderRequest: totalAmount must be > 0.');
        }
        if ($this->basket === []) {
            throw new \InvalidArgumentException('CreateOrderRequest: basket must contain at least one item.');
        }
        if ($this->ttl !== null && ($this->ttl < 2 || $this->ttl > 1440)) {
            throw new \InvalidArgumentException('CreateOrderRequest: ttl must be between 2 and 1440 minutes.');
        }
        if (count($this->splitTransfers) > 10) {
            throw new \InvalidArgumentException('CreateOrderRequest: splitTransfers cannot exceed 10 entries.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $purchaseUnits = [
            'total_amount' => $this->totalAmount,
            'basket'       => array_map(fn(BasketItem $item) => $item->toArray(), $this->basket),
        ];

        $data = [
            'callback_url'   => $this->callbackUrl,
            'purchase_units' => $purchaseUnits,
            'currency'       => $this->currency->value,
            'capture'        => $this->capture->value,
        ];

        if ($this->externalOrderId !== null) {
            $data['external_order_id'] = $this->externalOrderId;
        }

        if ($this->redirectUrl !== null) {
            $data['redirect_url'] = $this->redirectUrl;
        }

        if ($this->ttl !== null) {
            $data['ttl'] = $this->ttl;
        }

        if ($this->paymentMethods !== []) {
            $data['payment_method'] = array_map(
                fn(PaymentMethod $m) => $m->value,
                $this->paymentMethods,
            );
        }

        if ($this->splitTransfers !== []) {
            $data['config']['split']['transfers'] = array_map(
                fn(SplitTransfer $t) => $t->toArray(),
                $this->splitTransfers,
            );
        }

        if ($this->saveCard) {
            $data['config']['save_card'] = true;
        }

        return $data;
    }
}
