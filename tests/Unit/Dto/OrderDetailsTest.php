<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Dto;

use Bog\Payments\Dto\Response\OrderDetails;
use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\OrderStatus;
use Bog\Payments\Enum\PaymentMethod;
use Bog\Payments\Enum\PaymentStatusCode;
use PHPUnit\Framework\TestCase;

final class OrderDetailsTest extends TestCase
{
    /** Minimal valid payload — only required fields. */
    private function minimalPayload(): array
    {
        return [
            'order_id'       => 'ord-001',
            'order_status'   => ['key' => 'created', 'value' => 'Created'],
            'purchase_units' => [
                'request_amount' => '50.00',
                'transfer_amount' => '0.00',
                'refund_amount'  => '0.00',
                'currency_code'  => 'GEL',
            ],
            'zoned_create_date' => '2024-01-15T10:00:00.000000Z',
            'zoned_expire_date' => '2024-01-15T10:20:00.000000Z',
            'actions'           => [],
        ];
    }

    public function test_parses_required_fields(): void
    {
        $details = OrderDetails::fromArray($this->minimalPayload());

        self::assertSame('ord-001', $details->id);
        self::assertSame(OrderStatus::Created, $details->status);
        self::assertSame(Currency::GEL, $details->currency);
        self::assertSame(50.0, $details->requestedAmount);
        self::assertSame(0.0, $details->processedAmount);
        self::assertSame(0.0, $details->refundedAmount);
        self::assertSame([], $details->actions);
    }

    public function test_null_fields_when_payment_detail_absent(): void
    {
        $details = OrderDetails::fromArray($this->minimalPayload());

        self::assertNull($details->paymentMethod);
        self::assertNull($details->maskedCard);
        self::assertNull($details->transactionId);
        self::assertNull($details->paymentCode);
        self::assertNull($details->paymentCodeDescription);
        self::assertNull($details->cardType);
        self::assertNull($details->cardExpiryDate);
        self::assertNull($details->authCode);
        self::assertNull($details->parentOrderId);
        self::assertNull($details->paymentOption);
        self::assertNull($details->savedCardType);
        self::assertNull($details->pgTrxId);
    }

    public function test_null_capture_when_absent(): void
    {
        $details = OrderDetails::fromArray($this->minimalPayload());
        self::assertNull($details->capture);
    }

    public function test_parses_payment_detail_fields(): void
    {
        $payload = $this->minimalPayload();
        $payload['order_status'] = ['key' => 'completed', 'value' => 'Completed'];
        $payload['purchase_units']['transfer_amount'] = '50.00';
        $payload['payment_detail'] = [
            'transfer_method'    => ['key' => 'card', 'value' => 'Card'],
            'transaction_id'     => 'TXN-999',
            'auth_code'          => 'AUTH-123',
            'payer_identifier'   => '400000***0001',
            'code'               => '100',
            'code_description'   => 'Successful payment',
            'card_type'          => 'visa',
            'card_expiry_date'   => '12/30',
            'parent_order_id'    => 'parent-ord-abc',
            'pg_trx_id'          => 'PG-TRX-XYZ',
            'payment_option'     => 'direct_debit',
            'saved_card_type'    => 'subscription',
        ];

        $details = OrderDetails::fromArray($payload);

        self::assertSame(OrderStatus::Completed, $details->status);
        self::assertSame(PaymentMethod::Card, $details->paymentMethod);
        self::assertSame('TXN-999', $details->transactionId);
        self::assertSame('AUTH-123', $details->authCode);
        self::assertSame('400000***0001', $details->maskedCard);
        self::assertSame(PaymentStatusCode::SuccessfulPayment, $details->paymentCode);
        self::assertSame('Successful payment', $details->paymentCodeDescription);
        self::assertSame('visa', $details->cardType);
        self::assertSame('12/30', $details->cardExpiryDate);
        self::assertSame('parent-ord-abc', $details->parentOrderId);
        self::assertSame('PG-TRX-XYZ', $details->pgTrxId);
        self::assertSame('direct_debit', $details->paymentOption);
        self::assertSame('subscription', $details->savedCardType);
    }

    public function test_parses_capture_field(): void
    {
        $payload           = $this->minimalPayload();
        $payload['capture'] = 'manual';

        $details = OrderDetails::fromArray($payload);

        self::assertSame('manual', $details->capture);
    }

    public function test_parses_capture_automatic(): void
    {
        $payload           = $this->minimalPayload();
        $payload['capture'] = 'automatic';

        $details = OrderDetails::fromArray($payload);

        self::assertSame('automatic', $details->capture);
    }

    public function test_parses_payment_option_recurrent(): void
    {
        $payload = $this->minimalPayload();
        $payload['payment_detail'] = ['payment_option' => 'recurrent'];

        $details = OrderDetails::fromArray($payload);

        self::assertSame('recurrent', $details->paymentOption);
    }

    public function test_parses_saved_card_type_recurrent(): void
    {
        $payload = $this->minimalPayload();
        $payload['payment_detail'] = ['saved_card_type' => 'recurrent'];

        $details = OrderDetails::fromArray($payload);

        self::assertSame('recurrent', $details->savedCardType);
    }

    public function test_throws_on_missing_order_id(): void
    {
        $payload = $this->minimalPayload();
        unset($payload['order_id']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"order_id"');
        OrderDetails::fromArray($payload);
    }

    public function test_throws_on_missing_order_status(): void
    {
        $payload = $this->minimalPayload();
        unset($payload['order_status']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"order_status"');
        OrderDetails::fromArray($payload);
    }

    public function test_throws_on_missing_purchase_units(): void
    {
        $payload = $this->minimalPayload();
        unset($payload['purchase_units']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"purchase_units"');
        OrderDetails::fromArray($payload);
    }

    public function test_parses_buyer_fields(): void
    {
        $payload = $this->minimalPayload();
        $payload['buyer'] = [
            'full_name'    => 'John Doe',
            'email'        => 'john@example.com',
            'phone_number' => '+995555000000',
        ];

        $details = OrderDetails::fromArray($payload);

        self::assertSame('John Doe', $details->buyerName);
        self::assertSame('john@example.com', $details->buyerEmail);
        self::assertSame('+995555000000', $details->buyerPhone);
    }
}
