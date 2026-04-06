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

final class RefundFlowTest extends TestCase
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

    public function test_full_refund_sends_empty_body(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(200, [], json_encode([
            'key'       => 'request_received',
            'message'   => 'Refund request received',
            'action_id' => 'act-001',
        ])));

        $client   = $this->makeClient($mock);
        $response = $client->refund('ord-99', new RefundRequest()); // null amount = full refund

        self::assertSame('request_received', $response->key);
        self::assertSame('act-001', $response->actionId);

        $requests  = $mock->getRequests();
        $refundReq = $requests[1];
        $body      = json_decode((string) $refundReq->getBody(), true);

        self::assertIsArray($body);
        self::assertArrayNotHasKey('amount', $body); // no amount key for full refund
    }

    public function test_partial_refund_sends_amount(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(200, [], json_encode([
            'key'       => 'request_received',
            'message'   => 'Partial refund received',
            'action_id' => 'act-002',
        ])));

        $client = $this->makeClient($mock);
        $client->refund('ord-100', new RefundRequest(amount: 30.0));

        $requests  = $mock->getRequests();
        $refundReq = $requests[1];
        $body      = json_decode((string) $refundReq->getBody(), true);

        self::assertEqualsWithDelta(30.0, $body['amount'], 0.0001);
    }

    public function test_refund_url_encodes_order_id(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(200, [], json_encode([
            'key'     => 'request_received',
            'message' => 'ok',
        ])));

        $client = $this->makeClient($mock);
        $client->refund('ord/special id', new RefundRequest());

        $requests  = $mock->getRequests();
        $refundUri = (string) $requests[1]->getUri();

        self::assertStringContainsString('ord%2Fspecial%20id', $refundUri);
    }

    public function test_token_cached_between_create_and_refund(): void
    {
        $mock = new MockClient();
        // Only ONE token response — both calls must share it
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'ord-200',
            '_links' => ['redirect' => ['href' => 'https://p.bog.ge/']],
        ])));
        $mock->addResponse(new Response(200, [], json_encode([
            'key'     => 'request_received',
            'message' => 'ok',
        ])));

        $client = $this->makeClient($mock);

        $client->createOrder(
            new CreateOrderRequest('https://example.com/cb', 50.0, [new BasketItem('p1', 1, 50.0)]),
        );

        $client->refund('ord-200', new RefundRequest());

        // Total: 1 token + 1 createOrder + 1 refund = 3 requests
        self::assertCount(3, $mock->getRequests());
    }

    public function test_refund_idempotency_key_auto_generated(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(200, [], json_encode([
            'key'     => 'request_received',
            'message' => 'ok',
        ])));

        $client = $this->makeClient($mock);
        $client->refund('ord-300', new RefundRequest(50.0));

        $requests  = $mock->getRequests();
        $refundReq = $requests[1];
        $key       = $refundReq->getHeaderLine('Idempotency-Key');

        self::assertNotEmpty($key);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $key,
        );
    }

    public function test_api_error_on_refund_throws_api_exception(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(400, [], json_encode(['error' => 'already_refunded'])));

        $client = $this->makeClient($mock);

        try {
            $client->refund('ord-400', new RefundRequest());
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->statusCode);
            self::assertStringContainsString('already_refunded', $e->responseBody);
        }
    }

    public function test_subscribe_charges_saved_card(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'new-ord-500',
            '_links' => ['details' => ['href' => 'https://api.bog.ge/payments/v1/receipt/new-ord-500']],
        ])));

        $client   = $this->makeClient($mock);
        $response = $client->chargeCard('parent-ord-001', new SubscribeRequest(
            callbackUrl:     'https://example.com/cb',
            externalOrderId: 'RECURRING-001',
        ));

        self::assertSame('new-ord-500', $response->orderId);
        self::assertStringContainsString('new-ord-500', $response->detailsUrl ?? '');
    }

    public function test_subscribe_request_body_serialised(): void
    {
        $mock = new MockClient();
        $mock->addResponse($this->tokenResponse());
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'sub-ord',
            '_links' => [],
        ])));

        $client = $this->makeClient($mock);
        $client->chargeCard('parent-1', new SubscribeRequest('https://example.com/cb', 'EXT-REC'));

        $requests     = $mock->getRequests();
        $subscribeReq = $requests[1];
        $body         = json_decode((string) $subscribeReq->getBody(), true);

        self::assertSame('https://example.com/cb', $body['callback_url']);
        self::assertSame('EXT-REC', $body['external_order_id']);
    }

    public function test_refund_throws_on_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RefundRequest(amount: -10.0);
    }
}
