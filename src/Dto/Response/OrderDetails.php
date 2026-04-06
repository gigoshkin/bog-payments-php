<?php

declare(strict_types=1);

namespace Bog\Payments\Dto\Response;

use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\OrderStatus;
use Bog\Payments\Enum\PaymentMethod;

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
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'status', 'purchase_units'] as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException(
                    sprintf('OrderDetails: missing required field "%s".', $field),
                );
            }
        }

        $units = $data['purchase_units'];
        $buyer = $data['buyer'] ?? [];
        $pm    = $data['payment_method'] ?? [];

        $paymentMethod = isset($pm['method'])
            ? PaymentMethod::tryFrom((string) $pm['method'])
            : null;

        $actions = array_map(
            fn(array $a) => OrderAction::fromArray($a),
            $data['actions'] ?? [],
        );

        return new self(
            id:              (string) $data['id'],
            status:          OrderStatus::from((string) $data['status']),
            currency:        Currency::from((string) ($units['currency'] ?? 'GEL')),
            requestedAmount: (float) ($units['requested_amount'] ?? 0),
            processedAmount: (float) ($units['processed_amount'] ?? 0),
            refundedAmount:  (float) ($units['refunded_amount'] ?? 0),
            createdAt:       new \DateTimeImmutable((string) ($data['created_at'] ?? 'now')),
            expiresAt:       new \DateTimeImmutable((string) ($data['expires_at'] ?? 'now')),
            externalOrderId: isset($data['external_order_id']) ? (string) $data['external_order_id'] : null,
            paymentMethod:   $paymentMethod,
            maskedCard:      isset($pm['masked_id']) ? (string) $pm['masked_id'] : null,
            transactionId:   isset($pm['transaction_id']) ? (string) $pm['transaction_id'] : null,
            buyerEmail:      isset($buyer['email']) ? (string) $buyer['email'] : null,
            buyerPhone:      isset($buyer['phone_number']) ? (string) $buyer['phone_number'] : null,
            buyerName:       isset($buyer['full_name']) ? (string) $buyer['full_name'] : null,
            rejectionReason: isset($data['rejection_reason']) ? (string) $data['rejection_reason'] : null,
            actions:         $actions,
        );
    }
}
