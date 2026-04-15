<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Sandbox;

use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\BuyerInfo;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Dto\Response\CreateOrderResponse;
use Bog\Payments\Enum\CaptureMode;
use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\PaymentMethod;
use Bog\Payments\Idempotency\IdempotencyKeyGenerator;

/**
 * Verifies that order creation works against the real sandbox.
 *
 * These tests only verify that the API accepts the request and returns
 * a well-formed response. They do NOT complete a payment (that requires
 * visiting the redirect URL and entering a test card number).
 *
 * Test cards (any expiry/CVV):
 *   4000000000000001  — success
 *   4000000000000002  — declined (insufficient funds)
 *   5300000000000001  — Mastercard success
 */
final class CreateOrderTest extends SandboxTestCase
{
    private function basket(): array
    {
        return [new BasketItem('sku-test-001', 1, 10.0, 'Test Product')];
    }

    public function test_minimal_order_returns_order_id_and_redirect_url(): void
    {
        $client = $this->makeClient();

        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 10.0,
            basket:      $this->basket(),
        ));

        self::assertInstanceOf(CreateOrderResponse::class, $result);
        self::assertNotEmpty($result->orderId, 'orderId must not be empty');
        self::assertStringContainsString('bog.ge', $result->redirectUrl, 'redirectUrl must point to BOG');
        self::assertNotNull($result->detailsUrl, 'detailsUrl must be present');

        echo "\n[CreateOrder] orderId={$result->orderId} redirect={$result->redirectUrl}\n";
    }

    public function test_order_with_explicit_currency_and_capture(): void
    {
        $client = $this->makeClient();

        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl:     'https://httpbin.org/post',
            totalAmount:     25.50,
            basket:          [new BasketItem('sku-002', 2, 12.75, 'Item B')],
            currency:        Currency::GEL,
            capture:         CaptureMode::Automatic,
            externalOrderId: 'TEST-' . uniqid(),
        ));

        self::assertNotEmpty($result->orderId);
        self::assertNotEmpty($result->redirectUrl);
    }

    public function test_order_with_manual_capture(): void
    {
        $client = $this->makeClient();

        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl:     'https://httpbin.org/post',
            totalAmount:     50.0,
            basket:          $this->basket(),
            capture:         CaptureMode::Manual,
            externalOrderId: 'PREAUTH-' . uniqid(),
        ));

        self::assertNotEmpty($result->orderId);
        self::assertNotEmpty($result->redirectUrl);

        echo "\n[PreAuth] orderId={$result->orderId} redirect={$result->redirectUrl}\n";
        echo "  Pay with card 4000000000000001 (any expiry/CVV) to pre-authorize.\n";
        echo "  Use the Interactive test suite to confirm/cancel without setting env vars.\n";
    }

    public function test_order_with_payment_method_restriction(): void
    {
        $client = $this->makeClient();

        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl:    'https://httpbin.org/post',
            totalAmount:    15.0,
            basket:         $this->basket(),
            paymentMethods: [PaymentMethod::Card],
        ));

        self::assertNotEmpty($result->orderId);
    }

    public function test_order_with_redirect_urls(): void
    {
        $client = $this->makeClient();

        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl:  'https://httpbin.org/post',
            totalAmount:  20.0,
            basket:       $this->basket(),
            redirectUrl:  'https://httpbin.org/get?status=success',
            failUrl:      'https://httpbin.org/get?status=fail',
        ));

        self::assertNotEmpty($result->orderId);
    }

    public function test_order_with_ttl(): void
    {
        $client = $this->makeClient();

        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 5.0,
            basket:      $this->basket(),
            ttl:         15,
        ));

        self::assertNotEmpty($result->orderId);
    }

    public function test_order_with_buyer_info(): void
    {
        $client = $this->makeClient();

        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 30.0,
            basket:      $this->basket(),
            buyer:       new BuyerInfo(
                fullName:    'John Doe',
                maskedEmail: 'j***@example.com',
                maskedPhone: '+995 5** *** 001',
            ),
        ));

        self::assertNotEmpty($result->orderId);
    }

    public function test_order_with_multiple_basket_items(): void
    {
        $client = $this->makeClient();

        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 55.0,
            basket:      [
                new BasketItem('sku-a', 2, 20.0, 'Product A'),
                new BasketItem('sku-b', 1, 15.0, 'Product B'),
            ],
        ));

        self::assertNotEmpty($result->orderId);
    }

    public function test_idempotency_key_returns_same_order_on_retry(): void
    {
        $client         = $this->makeClient();
        $idempotencyKey = (new IdempotencyKeyGenerator())->generate();

        $request = new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 10.0,
            basket:      $this->basket(),
        );

        $first = $client->createOrder($request, $idempotencyKey);
        usleep(500_000); // extra pause between two back-to-back calls in same test
        $second = $client->createOrder($request, $idempotencyKey);

        self::assertSame($first->orderId, $second->orderId, 'Same idempotency key must return same order');
    }
}
