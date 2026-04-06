<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Integration;

use Bog\Payments\BogClient;
use Bog\Payments\BogConfig;
use Bog\Payments\Cache\InMemoryCache;
use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Dto\Request\RefundRequest;
use Bog\Payments\Dto\Request\SubscribeRequest;
use Bog\Payments\Exception\ApiException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * URL assertion and status-boundary tests.
 *
 * These exist specifically to kill:
 * - Concat / ConcatOperandRemoval on URL construction
 * - GreaterThanOrEqualTo on $status >= 300
 * - LogicalOr / IncrementInteger / DecrementInteger on status check
 * - MethodCallRemoval on saveCard / deleteCard HTTP call
 * - Coalesce on idempotency key (??=) for refund / saveCard / chargeCard
 */
final class BogClientUrlAndBoundaryTest extends TestCase
{
    private Psr17Factory $factory;
    private BogConfig    $config;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->config  = new BogConfig('cid', 'secret');
    }

    private function makeClient(MockClient $mock): BogClient
    {
        return BogClient::create($this->config, $mock, $this->factory, $this->factory, new InMemoryCache());
    }

    private function tokenResponse(): Response
    {
        return new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
        ]));
    }

    // -------------------------------------------------------------------------
    // URL assertions — kill Concat / ConcatOperandRemoval mutations
    // -------------------------------------------------------------------------

    public function test_create_order_posts_to_correct_url(): void
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

        $url = (string) $mock->getRequests()[1]->getUri();
        self::assertSame('https://api.bog.ge/payments/v1/ecommerce/orders', $url);
    }

    public function test_get_order_details_gets_correct_url(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(200, [], json_encode([
            'id'             => 'ord-abc',
            'status'         => 'completed',
            'purchase_units' => ['currency' => 'GEL', 'requested_amount' => 10.0, 'processed_amount' => 10.0, 'refunded_amount' => 0.0],
            'created_at'     => '2026-04-06T10:00:00Z',
            'expires_at'     => '2026-04-06T10:15:00Z',
        ])));

        $this->makeClient($mock)->getOrderDetails('ord-abc');

        $url = (string) $mock->getRequests()[1]->getUri();
        self::assertSame('https://api.bog.ge/payments/v1/receipt/ord-abc', $url);
    }

    public function test_refund_posts_to_correct_url(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(200, [], json_encode([
            'key' => 'request_received', 'message' => 'ok',
        ])));

        $this->makeClient($mock)->refund('ord-xyz', new RefundRequest());

        $url = (string) $mock->getRequests()[1]->getUri();
        self::assertSame('https://api.bog.ge/payments/v1/payment/refund/ord-xyz', $url);
    }

    public function test_save_card_puts_to_correct_url(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(202, [], ''));

        $this->makeClient($mock)->saveCard('ord-save');

        $requests = $mock->getRequests();
        self::assertCount(2, $requests); // token + saveCard — proves HTTP call is made

        $url = (string) $requests[1]->getUri();
        self::assertSame('PUT', $requests[1]->getMethod());
        self::assertSame('https://api.bog.ge/payments/v1/orders/ord-save/cards', $url);
    }

    public function test_delete_card_deletes_correct_url(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(202, [], ''));

        $this->makeClient($mock)->deleteCard('ord-del');

        $requests = $mock->getRequests();
        self::assertCount(2, $requests); // token + deleteCard — proves HTTP call is made

        $url = (string) $requests[1]->getUri();
        self::assertSame('DELETE', $requests[1]->getMethod());
        self::assertSame('https://api.bog.ge/payments/v1/charges/card/ord-del', $url);
    }

    public function test_charge_card_posts_to_correct_url(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'new-ord',
            '_links' => ['details' => ['href' => 'https://api.bog.ge/payments/v1/receipt/new-ord']],
        ])));

        $this->makeClient($mock)->chargeCard('parent-ord', new SubscribeRequest());

        $url = (string) $mock->getRequests()[1]->getUri();
        self::assertSame('https://api.bog.ge/payments/v1/ecommerce/orders/parent-ord/subscribe', $url);
    }

    // -------------------------------------------------------------------------
    // Status code boundary — kill >= 300 → > 300, LogicalOr, ±1 mutations
    // -------------------------------------------------------------------------

    public function test_status_300_throws_api_exception(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(300, [], '{"error":"redirect"}'));

        try {
            $this->makeClient($mock)->getOrderDetails('ord-1');
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(300, $e->statusCode);
        }
    }

    public function test_status_199_throws_api_exception(): void
    {
        // Boundary: < 200 must throw. Mutation: < 200 → < 199 would allow 199 through.
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(199, [], '{"error":"too early"}'));

        $this->expectException(ApiException::class);
        $this->makeClient($mock)->getOrderDetails('ord-1');
    }

    public function test_status_200_does_not_throw(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(200, [], json_encode([
            'id'             => 'ord-200',
            'status'         => 'completed',
            'purchase_units' => ['currency' => 'GEL', 'requested_amount' => 10.0, 'processed_amount' => 10.0, 'refunded_amount' => 0.0],
            'created_at'     => '2026-04-06T10:00:00Z',
            'expires_at'     => '2026-04-06T10:15:00Z',
        ])));

        $details = $this->makeClient($mock)->getOrderDetails('ord-200');
        self::assertSame('ord-200', $details->id);
    }

    public function test_status_500_throws_api_exception_with_correct_code(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(500, [], '{"error":"server error"}'));

        try {
            $this->makeClient($mock)->getOrderDetails('ord-1');
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(500, $e->statusCode);
        }
    }

    // -------------------------------------------------------------------------
    // Idempotency key coalesce — kill Coalesce mutations on refund / saveCard / chargeCard
    // -------------------------------------------------------------------------

    public function test_refund_uses_provided_idempotency_key(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(200, [], json_encode([
            'key' => 'request_received', 'message' => 'ok',
        ])));

        $this->makeClient($mock)->refund('ord-1', new RefundRequest(), 'my-refund-key');

        self::assertSame('my-refund-key', $mock->getRequests()[1]->getHeaderLine('Idempotency-Key'));
    }

    public function test_save_card_uses_provided_idempotency_key(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(202, [], ''));

        $this->makeClient($mock)->saveCard('ord-1', 'save-card-key');

        self::assertSame('save-card-key', $mock->getRequests()[1]->getHeaderLine('Idempotency-Key'));
    }

    public function test_charge_card_uses_provided_idempotency_key(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'sub-ord',
            '_links' => [],
        ])));

        $this->makeClient($mock)->chargeCard('parent-1', new SubscribeRequest(), 'charge-key-999');

        self::assertSame('charge-key-999', $mock->getRequests()[1]->getHeaderLine('Idempotency-Key'));
    }

    // -------------------------------------------------------------------------
    // CreateOrderRequest FalseValue / LessThan / GreaterThan boundaries
    // -------------------------------------------------------------------------

    public function test_create_order_with_max_ttl_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        new CreateOrderRequest(
            callbackUrl: 'https://example.com/cb',
            totalAmount: 10.0,
            basket:      [new BasketItem('p1', 1, 10.0)],
            ttl:         1440,
        );
    }

    public function test_create_order_with_min_ttl_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        new CreateOrderRequest(
            callbackUrl: 'https://example.com/cb',
            totalAmount: 10.0,
            basket:      [new BasketItem('p1', 1, 10.0)],
            ttl:         2,
        );
    }

    public function test_create_order_ttl_1_throws(): void
    {
        // ttl = 1 < 2 (min allowed). Mutation: `< 2` → `< 1` would allow ttl=1.
        $this->expectException(\InvalidArgumentException::class);
        new CreateOrderRequest(
            callbackUrl: 'https://example.com/cb',
            totalAmount: 10.0,
            basket:      [new BasketItem('p1', 1, 10.0)],
            ttl:         1,
        );
    }

    public function test_create_order_ttl_1441_throws(): void
    {
        // ttl = 1441 > 1440 (max allowed). Mutation: `> 1440` → `> 1441` would allow 1441.
        $this->expectException(\InvalidArgumentException::class);
        new CreateOrderRequest(
            callbackUrl: 'https://example.com/cb',
            totalAmount: 10.0,
            basket:      [new BasketItem('p1', 1, 10.0)],
            ttl:         1441,
        );
    }
}
