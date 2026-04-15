<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Request;

use Bog\Payments\Enum\CaptureMode;
use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\PaymentMethod;

final readonly class CreateOrderRequest
{
    /**
     * @param BasketItem[]              $basket          At least one item required.
     * @param PaymentMethod[]           $paymentMethods  Restrict to these methods. Empty = all allowed.
     * @param SplitTransfer[]           $splitTransfers  GEL-only multi-IBAN routing (max 10).
     * @param ExternalGooglePayConfig|null $googlePay    Merchant-hosted Google Pay config (token from JS SDK).
     * @param ExternalApplePayConfig|null  $applePay     Merchant-hosted Apple Pay config.
     */
    public function __construct(
        public string                      $callbackUrl,
        public float                       $totalAmount,
        public array                       $basket,
        public Currency                    $currency             = Currency::GEL,
        public CaptureMode                 $capture              = CaptureMode::Automatic,
        public ?string                     $externalOrderId      = null,
        public ?string                     $redirectUrl          = null,
        public ?string                     $failUrl              = null,
        public ?int                        $ttl                  = null,
        public array                       $paymentMethods       = [],
        public array                       $splitTransfers       = [],
        public ?BuyerInfo                  $buyer                = null,
        public ?string                     $applicationType      = null,
        public ?float                      $totalDiscountAmount  = null,
        public ?float                      $deliveryAmount       = null,
        public ?ExternalGooglePayConfig    $googlePay            = null,
        public ?ExternalApplePayConfig     $applePay             = null,
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
            'currency'     => $this->currency->value,
            'basket'       => array_map(static fn(BasketItem $item) => $item->toArray(), $this->basket),
        ];

        if ($this->totalDiscountAmount !== null) {
            $purchaseUnits['total_discount_amount'] = $this->totalDiscountAmount;
        }

        if ($this->deliveryAmount !== null) {
            $purchaseUnits['delivery'] = ['amount' => $this->deliveryAmount];
        }

        $data = [
            'callback_url'   => $this->callbackUrl,
            'purchase_units' => $purchaseUnits,
            'capture'        => $this->capture->value,
        ];

        if ($this->applicationType !== null) {
            $data['application_type'] = $this->applicationType;
        }

        if ($this->buyer !== null) {
            $data['buyer'] = $this->buyer->toArray();
        }

        if ($this->externalOrderId !== null) {
            $data['external_order_id'] = $this->externalOrderId;
        }

        if ($this->redirectUrl !== null || $this->failUrl !== null) {
            $redirectUrls = [];
            if ($this->redirectUrl !== null) {
                $redirectUrls['success'] = $this->redirectUrl;
            }
            if ($this->failUrl !== null) {
                $redirectUrls['fail'] = $this->failUrl;
            }
            $data['redirect_urls'] = $redirectUrls;
        }

        if ($this->ttl !== null) {
            $data['ttl'] = $this->ttl;
        }

        if ($this->paymentMethods !== []) {
            $data['payment_method'] = array_map(
                static fn(PaymentMethod $m) => $m->value,
                $this->paymentMethods,
            );
        }

        if ($this->splitTransfers !== []) {
            $data['config']['split']['transfers'] = array_map(
                static fn(SplitTransfer $t) => $t->toArray(),
                $this->splitTransfers,
            );
        }

        if ($this->googlePay !== null) {
            $data['config']['google_pay'] = $this->googlePay->toArray();
        }

        if ($this->applePay !== null) {
            $data['config']['apple_pay'] = $this->applePay->toArray();
        }

        return $data;
    }
}
