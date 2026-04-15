<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Integration;

use Bog\Payments\BogClient;
use Bog\Payments\BogConfig;
use Bog\Payments\Cache\InMemoryCache;
use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Dto\Request\RefundRequest;
use Bog\Payments\Exception\ApiException;
use Bog\Payments\Exception\AuthenticationException;
use Bog\Payments\Exception\NetworkException;
use Bog\Payments\Exception\OrderNotFoundException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class BogClientTest extends TestCase
{
    private Psr17Factory $factory;
    private BogConfig    $config;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->config  = new BogConfig('client-id', 'client-secret');
    }

    private function makeClient(MockClient $mock, ?string $webhookKey = null): BogClient
    {
        $cfg = $webhookKey !== null
            ? new BogConfig('client-id', 'client-secret', webhookPublicKey: $webhookKey)
            : $this->config;

        return BogClient::create($cfg, $mock, $this->factory, $this->factory, new InMemoryCache());
    }

    private function tokenResponse(): Response
    {
        return new Response(200, [], json_encode([
            'access_token' => 'test-token',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
        ]));
    }

    // -------------------------------------------------------------------------
    // createOrder
    // -------------------------------------------------------------------------

    public function test_create_order_maps_response_correctly(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'ord-abc123',
            '_links' => [
                'redirect' => ['href' => 'https://payment.bog.ge/?order_id=ord-abc123'],
                'details'  => ['href' => 'https://api.bog.ge/payments/v1/receipt/ord-abc123'],
            ],
        ])));

        $client   = $this->makeClient($mock);
        $response = $client->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://example.com/cb',
            totalAmount: 100.0,
            basket:      [new BasketItem('p1', 1, 100.0)],
        ));

        self::assertSame('ord-abc123', $response->orderId);
        self::assertStringContainsString('ord-abc123', $response->redirectUrl);
    }

    public function test_create_order_auto_generates_idempotency_key(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'ord-1',
            '_links' => ['redirect' => ['href' => 'https://payment.bog.ge/?order_id=ord-1']],
        ])));

        $this->makeClient($mock)->createOrder(new CreateOrderRequest(
            callbackUrl: 'https://example.com/cb',
            totalAmount: 10.0,
            basket:      [new BasketItem('p1', 1, 10.0)],
        ));

        $orderRequest = $mock->getLastRequest();
        $idempotencyKey = $orderRequest->getHeaderLine('Idempotency-Key');

        self::assertNotEmpty($idempotencyKey);
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        self::assertMatchesRegularExpression($pattern, $idempotencyKey);
    }

    public function test_create_order_respects_provided_idempotency_key(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'ord-1',
            '_links' => ['redirect' => ['href' => 'https://payment.bog.ge/?order_id=ord-1']],
        ])));

        $this->makeClient($mock)->createOrder(
            new CreateOrderRequest('https://example.com/cb', 10.0, [new BasketItem('p1', 1, 10.0)]),
            'my-key-abc',
        );

        self::assertSame('my-key-abc', $mock->getLastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function test_create_order_sends_bearer_token(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'ord-1',
            '_links' => ['redirect' => ['href' => 'https://p.bog.ge/']],
        ])));

        $this->makeClient($mock)->createOrder(
            new CreateOrderRequest('https://example.com/cb', 10.0, [new BasketItem('p1', 1, 10.0)]),
        );

        self::assertSame('Bearer test-token', $mock->getLastRequest()->getHeaderLine('Authorization'));
    }

    // -------------------------------------------------------------------------
    // Error mapping
    // -------------------------------------------------------------------------

    public function test_create_order_maps_404_to_order_not_found(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(404, [], '{"error":"not found"}'));

        $this->expectException(OrderNotFoundException::class);
        $this->makeClient($mock)->createOrder(
            new CreateOrderRequest('https://example.com/cb', 10.0, [new BasketItem('p1', 1, 10.0)]),
        );
    }

    public function test_create_order_maps_422_to_api_exception(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(422, [], '{"error":"invalid amount"}'));

        try {
            $this->makeClient($mock)->createOrder(
                new CreateOrderRequest('https://example.com/cb', 10.0, [new BasketItem('p1', 1, 10.0)]),
            );
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(422, $e->statusCode);
            self::assertStringContainsString('invalid amount', $e->responseBody);
        }
    }

    public function test_network_error_throws_network_exception(): void
    {
        $networkException = new class('timeout') extends \RuntimeException implements ClientExceptionInterface {};

        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addException($networkException);

        $this->expectException(NetworkException::class);
        $this->makeClient($mock)->createOrder(
            new CreateOrderRequest('https://example.com/cb', 10.0, [new BasketItem('p1', 1, 10.0)]),
        );
    }

    // -------------------------------------------------------------------------
    // Token re-use (caching)
    // -------------------------------------------------------------------------

    public function test_token_is_reused_across_calls(): void
    {
        $mock = new MockClient();
        // Only one token response — second call must reuse it
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'ord-1',
            '_links' => ['redirect' => ['href' => 'https://p.bog.ge/']],
        ])));
        $mock->addResponse(new Response(200, [], json_encode([
            'order_id'          => 'ord-1',
            'order_status'      => ['key' => 'completed', 'value' => 'დასრულებული'],
            'purchase_units'    => [
                'currency_code'   => 'GEL',
                'request_amount'  => '100.0',
                'transfer_amount' => '100.0',
                'refund_amount'   => '0.0',
            ],
            'zoned_create_date' => '2026-04-06T10:00:00Z',
            'zoned_expire_date' => '2026-04-06T10:15:00Z',
        ])));

        $client = $this->makeClient($mock);

        $client->createOrder(
            new CreateOrderRequest('https://example.com/cb', 100.0, [new BasketItem('p1', 1, 100.0)]),
        );

        $client->getOrderDetails('ord-1');

        // Three requests: token, createOrder, getOrderDetails
        $requests = $mock->getRequests();
        self::assertCount(3, $requests);

        // Both API requests use the same token
        self::assertSame('Bearer test-token', $requests[1]->getHeaderLine('Authorization'));
        self::assertSame('Bearer test-token', $requests[2]->getHeaderLine('Authorization'));
    }

    // -------------------------------------------------------------------------
    // 401 retry with token refresh
    // -------------------------------------------------------------------------

    public function test_401_triggers_token_refresh_and_retries(): void
    {
        $mock = new MockClient();
        // First token fetch
        $mock->addResponse($this->tokenResponse());
        // API returns 401 (token invalidated mid-session)
        $mock->addResponse(new Response(401, [], 'Unauthorized'));
        // Token refresh returns new token
        $mock->addResponse(new Response(200, [], json_encode([
            'access_token' => 'refreshed-token',
            'expires_in'   => 3600,
        ])));
        // Retry with refreshed token succeeds
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'ord-2',
            '_links' => ['redirect' => ['href' => 'https://p.bog.ge/']],
        ])));

        $client   = $this->makeClient($mock);
        $response = $client->createOrder(
            new CreateOrderRequest('https://example.com/cb', 10.0, [new BasketItem('p1', 1, 10.0)]),
        );

        self::assertSame('ord-2', $response->orderId);

        // The retry request should use the refreshed token
        $requests = $mock->getRequests();
        self::assertSame('Bearer refreshed-token', end($requests)->getHeaderLine('Authorization'));
    }

    public function test_persistent_401_after_refresh_throws_authentication_exception(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(401, [], 'Unauthorized')); // first 401
        $mock->addResponse(new Response(200, [], json_encode([    // token refresh
            'access_token' => 'refreshed-token',
            'expires_in'   => 3600,
        ])));
        $mock->addResponse(new Response(401, [], 'Unauthorized')); // still 401 after refresh

        $client = $this->makeClient($mock);

        $this->expectException(AuthenticationException::class);
        $client->createOrder(
            new CreateOrderRequest('https://example.com/cb', 10.0, [new BasketItem('p1', 1, 10.0)]),
        );
    }

    // -------------------------------------------------------------------------
    // deleteCard / saveCard (no-body responses)
    // -------------------------------------------------------------------------

    public function test_delete_card_accepts_202(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(202, [], ''));

        $this->expectNotToPerformAssertions();
        $this->makeClient($mock)->deleteCard('ord-123');
    }

    public function test_save_card_accepts_202(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(202, [], ''));

        $this->expectNotToPerformAssertions();
        $this->makeClient($mock)->saveCard('ord-123');
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    public function test_verify_and_parse_webhook_without_public_key_throws(): void
    {
        $mock   = new MockClient();
        $client = $this->makeClient($mock); // no webhookPublicKey configured

        $this->expectException(\Bog\Payments\Exception\WebhookVerificationException::class);
        $client->verifyAndParseWebhook('{"event":"order_payment","body":{}}', 'sig');
    }

    // -------------------------------------------------------------------------
    // getOrderDetails — BOG returns 400 for unknown orders (not 404)
    // -------------------------------------------------------------------------

    public function test_get_order_details_maps_400_with_order_id_body_to_order_not_found(): void
    {
        // BOG returns HTTP 400 {"message":"Invalid order_id"} for non-existent orders.
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(400, [], '{"message":"Invalid order_id"}'));

        $this->expectException(OrderNotFoundException::class);
        $this->makeClient($mock)->getOrderDetails('bogus-id');
    }

    public function test_get_order_details_400_without_order_id_in_body_throws_api_exception(): void
    {
        // A 400 that isn't about an unknown order should remain an ApiException.
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(400, [], '{"message":"Bad request"}'));

        try {
            $this->makeClient($mock)->getOrderDetails('ord-bad');
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->statusCode);
        }
    }

    public function test_get_order_details_non_400_with_order_id_body_throws_api_exception(): void
    {
        // Only status 400 should trigger the OrderNotFoundException path.
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(422, [], '{"message":"Invalid order_id"}'));

        try {
            $this->makeClient($mock)->getOrderDetails('ord-1');
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(422, $e->statusCode);
            self::assertNotInstanceOf(OrderNotFoundException::class, $e);
        }
    }
}
