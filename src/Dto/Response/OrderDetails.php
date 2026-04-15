<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\OrderStatus;
use Bog\Payments\Enum\PaymentMethod;
use Bog\Payments\Enum\PaymentStatusCode;

final readonly class OrderDetails
{
    /**
     * @param OrderAction[] $actions
     */
    public function __construct(
        public string              $id,
        public OrderStatus         $status,
        public Currency            $currency,
        public float               $requestedAmount,
        public float               $processedAmount,
        public float               $refundedAmount,
        public \DateTimeImmutable  $createdAt,
        public \DateTimeImmutable  $expiresAt,
        public ?string             $externalOrderId,
        public ?PaymentMethod      $paymentMethod,
        public ?string             $maskedCard,
        public ?string             $transactionId,
        public ?string             $buyerEmail,
        public ?string             $buyerPhone,
        public ?string             $buyerName,
        public ?string             $rejectionReason,
        public array               $actions,
        public ?PaymentStatusCode  $paymentCode,
        public ?string             $paymentCodeDescription,
        public ?string             $cardType,
        public ?string             $cardExpiryDate,
        public ?string             $authCode,
        public ?string             $parentOrderId,
        public ?string             $paymentOption,
        public ?string             $savedCardType,
        public ?string             $capture,
        public ?string             $pgTrxId,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['order_id', 'order_status', 'purchase_units'] as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException(
                    sprintf('OrderDetails: missing required field "%s".', $field),
                );
            }
        }

        $units = $data['purchase_units'];
        $buyer = $data['buyer'] ?? [];
        $pd    = $data['payment_detail'] ?? [];

        $paymentMethod = isset($pd['transfer_method']['key'])
            ? PaymentMethod::tryFrom((string) $pd['transfer_method']['key'])
            : null;

        $rawCode = isset($pd['code']) ? (int) $pd['code'] : null;

        $actions = array_map(
            static fn(array $a) => OrderAction::fromArray($a),
            $data['actions'] ?? [],
        );

        return new self(
            id:                     (string) $data['order_id'],
            status:                 OrderStatus::from((string) ($data['order_status']['key'] ?? '')),
            currency:               Currency::from((string) ($units['currency_code'] ?? 'GEL')),
            requestedAmount:        (float) ($units['request_amount'] ?? 0),
            processedAmount:        (float) ($units['transfer_amount'] ?? 0),
            refundedAmount:         (float) ($units['refund_amount'] ?? 0),
            createdAt:              new \DateTimeImmutable((string) ($data['zoned_create_date'] ?? 'now')),
            expiresAt:              new \DateTimeImmutable((string) ($data['zoned_expire_date'] ?? 'now')),
            externalOrderId:        isset($data['external_order_id']) ? (string) $data['external_order_id'] : null,
            paymentMethod:          $paymentMethod,
            maskedCard:             isset($pd['payer_identifier']) ? (string) $pd['payer_identifier'] : null,
            transactionId:          isset($pd['transaction_id']) ? (string) $pd['transaction_id'] : null,
            buyerEmail:             isset($buyer['email']) ? (string) $buyer['email'] : null,
            buyerPhone:             isset($buyer['phone_number']) ? (string) $buyer['phone_number'] : null,
            buyerName:              isset($buyer['full_name']) ? (string) $buyer['full_name'] : null,
            rejectionReason:        isset($data['reject_reason']) ? (string) $data['reject_reason'] : null,
            actions:                $actions,
            paymentCode:            $rawCode !== null ? PaymentStatusCode::tryFrom($rawCode) : null,
            paymentCodeDescription: isset($pd['code_description']) ? (string) $pd['code_description'] : null,
            cardType:               isset($pd['card_type']) ? (string) $pd['card_type'] : null,
            cardExpiryDate:         isset($pd['card_expiry_date']) ? (string) $pd['card_expiry_date'] : null,
            authCode:               isset($pd['auth_code']) ? (string) $pd['auth_code'] : null,
            parentOrderId:          isset($pd['parent_order_id']) ? (string) $pd['parent_order_id'] : null,
            paymentOption:          isset($pd['payment_option']) ? (string) $pd['payment_option'] : null,
            savedCardType:          isset($pd['saved_card_type']) ? (string) $pd['saved_card_type'] : null,
            capture:                isset($data['capture']) ? (string) $data['capture'] : null,
            pgTrxId:                isset($pd['pg_trx_id']) ? (string) $pd['pg_trx_id'] : null,
        );
    }
}
