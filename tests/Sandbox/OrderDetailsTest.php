<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Sandbox;

use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Dto\Response\OrderDetails;
use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\OrderStatus;
use Bog\Payments\Exception\OrderNotFoundException;
use Bog\Payments\Idempotency\IdempotencyKeyGenerator;

/**
 * Verifies OrderDetails parsing against the real sandbox.
 */
final class OrderDetailsTest extends SandboxTestCase
{
    private function createOrder(float $amount = 10.0, ?string $externalId = null): string
    {
        return $this->makeClient()->createOrder(new CreateOrderRequest(
            callbackUrl:     'https://httpbin.org/post',
            totalAmount:     $amount,
            basket:          [new BasketItem('sku-details-test', 1, $amount, 'Details Test')],
            externalOrderId: $externalId,
        ))->orderId;
    }

    // -------------------------------------------------------------------------
    // Response structure
    // -------------------------------------------------------------------------

    public function test_create_order_response_has_correct_sandbox_urls(): void
    {
        $result = $this->makeClient()->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 10.0,
            basket:      [new BasketItem('sku-url-check', 1, 10.0)],
        ));

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $result->orderId,
            'orderId must be a UUID v4',
        );
        self::assertStringContainsString('payment-sandbox.bog.ge', $result->redirectUrl);
        self::assertStringContainsString($result->orderId, $result->redirectUrl);
        self::assertNotNull($result->detailsUrl);
        self::assertStringContainsString('api-sandbox.bog.ge', $result->detailsUrl);
        self::assertStringContainsString($result->orderId, $result->detailsUrl);

        echo "\n[URLs] redirect={$result->redirectUrl}\n       details={$result->detailsUrl}\n";
    }

    // -------------------------------------------------------------------------
    // Unpaid order field coverage
    // -------------------------------------------------------------------------

    public function test_new_order_all_fields(): void
    {
        $client  = $this->makeClient();
        $orderId = $this->createOrder(15.0);
        $details = $client->getOrderDetails($orderId);

        // Identity
        self::assertInstanceOf(OrderDetails::class, $details);
        self::assertSame($orderId, $details->id);

        // Status
        self::assertSame(OrderStatus::Created, $details->status);

        // Amounts — unpaid order: requested set, processed/refunded zero
        self::assertSame(15.0, $details->requestedAmount);
        self::assertSame(0.0, $details->processedAmount);
        self::assertSame(0.0, $details->refundedAmount);

        // Currency
        self::assertSame(Currency::GEL, $details->currency);

        // Dates — expiresAt must be after createdAt
        self::assertInstanceOf(\DateTimeImmutable::class, $details->createdAt);
        self::assertInstanceOf(\DateTimeImmutable::class, $details->expiresAt);
        self::assertGreaterThan($details->createdAt, $details->expiresAt);

        // Payment fields — null before payment
        self::assertNull($details->paymentMethod, 'paymentMethod must be null before payment');
        self::assertNull($details->maskedCard,     'maskedCard must be null before payment');
        self::assertNull($details->transactionId,  'transactionId must be null before payment');
        self::assertNull($details->paymentCode,    'paymentCode must be null before payment');
        self::assertNull($details->paymentCodeDescription);
        self::assertNull($details->cardType);
        self::assertNull($details->cardExpiryDate);
        self::assertNull($details->authCode);
        self::assertNull($details->parentOrderId);

        // Buyer — null when not set in request
        self::assertNull($details->buyerEmail);
        self::assertNull($details->buyerPhone);
        self::assertNull($details->buyerName);

        // Rejection — null for new order
        self::assertNull($details->rejectionReason);

        // Actions — empty for new order
        self::assertIsArray($details->actions);
        self::assertCount(0, $details->actions);

        echo "\n[NewOrder] id={$details->id} amount={$details->requestedAmount} status={$details->status->value}\n";
    }

    public function test_external_order_id_is_preserved(): void
    {
        $externalId = 'EXT-SANDBOX-' . uniqid();
        $orderId    = $this->createOrder(externalId: $externalId);

        $details = $this->makeClient()->getOrderDetails($orderId);

        self::assertSame($externalId, $details->externalOrderId);
    }

    public function test_requested_amount_matches_order(): void
    {
        $orderId = $this->createOrder(37.50);
        $details = $this->makeClient()->getOrderDetails($orderId);

        self::assertSame(37.5, $details->requestedAmount);
    }

    public function test_currency_gel_preserved(): void
    {
        $result  = $this->makeClient()->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 5.0,
            basket:      [new BasketItem('sku-gel', 1, 5.0)],
            currency:    Currency::GEL,
        ));
        $details = $this->makeClient()->getOrderDetails($result->orderId);

        self::assertSame(Currency::GEL, $details->currency);
    }

    // -------------------------------------------------------------------------
    // Error cases
    // -------------------------------------------------------------------------

    public function test_unknown_order_id_throws_not_found(): void
    {
        $this->expectException(OrderNotFoundException::class);

        // BOG returns 400 "Invalid order_id" for unknown orders — normalised to OrderNotFoundException.
        $this->makeClient()->getOrderDetails((new IdempotencyKeyGenerator())->generate());
    }
}
