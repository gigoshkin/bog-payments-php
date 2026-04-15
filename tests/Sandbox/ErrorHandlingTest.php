<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Sandbox;

use Bog\Payments\BogClient;
use Bog\Payments\BogConfig;
use Bog\Payments\Cache\InMemoryCache;
use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\CancelPreAuthRequest;
use Bog\Payments\Dto\Request\ConfirmPreAuthRequest;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Dto\Request\RefundRequest;
use Bog\Payments\Exception\ApiException;
use Bog\Payments\Exception\AuthenticationException;
use Bog\Payments\Exception\OrderNotFoundException;
use Bog\Payments\Idempotency\IdempotencyKeyGenerator;
use Http\Client\Curl\Client as CurlClient;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Verifies that the library maps BOG error responses to the correct exception types.
 */
final class ErrorHandlingTest extends SandboxTestCase
{
    private function freshOrderId(): string
    {
        return $this->makeClient()->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 10.0,
            basket:      [new BasketItem('sku-err-test', 1, 10.0)],
        ))->orderId;
    }

    private function fakeOrderId(): string
    {
        return (new IdempotencyKeyGenerator())->generate();
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function test_wrong_credentials_throw_authentication_exception(): void
    {
        $this->expectException(AuthenticationException::class);

        $config  = new BogConfig('wrong-client-id', 'wrong-secret', self::BASE_URL, self::TOKEN_URL);
        $factory = new Psr17Factory();
        $http    = new CurlClient(null, null, [CURLOPT_ENCODING => '']);
        $client  = BogClient::create($config, $http, $factory, $factory, new InMemoryCache());

        $client->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://httpbin.org/post',
            totalAmount: 10.0,
            basket:      [new BasketItem('sku-x', 1, 10.0)],
        ));
    }

    // -------------------------------------------------------------------------
    // Order not found
    // -------------------------------------------------------------------------

    public function test_get_details_for_unknown_order_throws_not_found(): void
    {
        $this->expectException(OrderNotFoundException::class);
        $this->makeClient()->getOrderDetails($this->fakeOrderId());
    }

    public function test_refund_unknown_order_throws_not_found_or_api_error(): void
    {
        // BOG may return 400 or 404 for an order that doesn't exist.
        // Either OrderNotFoundException or ApiException is acceptable.
        $this->expectException(\Bog\Payments\Exception\BogException::class);

        $this->makeClient()->refund($this->fakeOrderId(), new RefundRequest());
    }

    public function test_confirm_preauth_on_unknown_order_throws(): void
    {
        $this->expectException(\Bog\Payments\Exception\BogException::class);

        $this->makeClient()->confirmPreAuthorization(
            $this->fakeOrderId(),
            new ConfirmPreAuthRequest(),
        );
    }

    public function test_cancel_preauth_on_unknown_order_throws(): void
    {
        $this->expectException(\Bog\Payments\Exception\BogException::class);

        $this->makeClient()->cancelPreAuthorization(
            $this->fakeOrderId(),
            new CancelPreAuthRequest(),
        );
    }

    // -------------------------------------------------------------------------
    // Wrong state
    // -------------------------------------------------------------------------

    public function test_refund_unpaid_order_throws_api_exception(): void
    {
        // Refunding an order that has never been paid should be rejected by BOG.
        $this->expectException(ApiException::class);

        $orderId = $this->freshOrderId();
        $this->makeClient()->refund($orderId, new RefundRequest());
    }

    public function test_confirm_preauth_on_unpaid_auto_capture_order_throws(): void
    {
        // Pre-auth confirm requires a paid capture=manual order. Calling it on a regular unpaid order should fail.
        $this->expectException(\Bog\Payments\Exception\BogException::class);

        $orderId = $this->freshOrderId();
        $this->makeClient()->confirmPreAuthorization($orderId, new ConfirmPreAuthRequest());
    }

    public function test_save_card_on_unpaid_order_throws_api_exception(): void
    {
        // saveCard requires a completed order.
        $this->expectException(ApiException::class);

        $orderId = $this->freshOrderId();
        $this->makeClient()->saveCard($orderId);
    }
}
