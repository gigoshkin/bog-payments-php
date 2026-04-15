<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Integration;

use Bog\Payments\BogClient;
use Bog\Payments\BogConfig;
use Bog\Payments\Cache\InMemoryCache;
use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Enum\CaptureMode;
use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\PaymentMethod;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class CreateOrderFlowTest extends TestCase
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

    public function test_full_create_order_flow(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'access_token' => 'tok-xyz',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
        ])));
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'order-999',
            '_links' => [
                'redirect' => ['href' => 'https://payment.bog.ge/?order_id=order-999'],
                'details'  => ['href' => 'https://api.bog.ge/payments/v1/receipt/order-999'],
            ],
        ])));

        $client = $this->makeClient($mock);
        $result = $client->createOrder(new CreateOrderRequest(
            callbackUrl:     'https://shop.example.com/webhook',
            totalAmount:     250.0,
            basket:          [
                new BasketItem('sku-a', 2, 100.0, 'Product A'),
                new BasketItem('sku-b', 1, 50.0),
            ],
            currency:        Currency::GEL,
            capture:         CaptureMode::Automatic,
            externalOrderId: 'MY-ORDER-001',
            redirectUrl:     'https://shop.example.com/return',
            ttl:             30,
            paymentMethods:  [PaymentMethod::Card, PaymentMethod::GooglePay],
        ));

        self::assertSame('order-999', $result->orderId);
        self::assertSame('https://payment.bog.ge/?order_id=order-999', $result->redirectUrl);
        self::assertSame('https://api.bog.ge/payments/v1/receipt/order-999', $result->detailsUrl);
    }

    public function test_request_body_contains_all_fields(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
        ])));
        $mock->addResponse(new Response(201, [], json_encode([
            'id'     => 'ord-1',
            '_links' => ['redirect' => ['href' => 'https://p.bog.ge/']],
        ])));

        $client = $this->makeClient($mock);
        $client->createOrder(new CreateOrderRequest(
            callbackUrl:     'https://example.com/cb',
            totalAmount:     99.99,
            basket:          [new BasketItem('prod-x', 3, 33.33, 'Test Product')],
            currency:        Currency::USD,
            capture:         CaptureMode::Manual,
            externalOrderId: 'EXT-001',
            ttl:             60,
            paymentMethods:  [PaymentMethod::ApplePay],
        ));

        $requests  = $mock->getRequests();
        $orderReq  = $requests[1]; // 0=token, 1=createOrder
        $body      = json_decode((string) $orderReq->getBody(), true);

        self::assertSame('https://example.com/cb', $body['callback_url']);
        self::assertSame(99.99, $body['purchase_units']['total_amount']);
        self::assertSame('USD', $body['purchase_units']['currency']);
        self::assertSame('manual', $body['capture']);
        self::assertSame('EXT-001', $body['external_order_id']);
        self::assertSame(60, $body['ttl']);
        self::assertSame(['apple_pay'], $body['payment_method']);
        self::assertCount(1, $body['purchase_units']['basket']);
        self::assertSame('prod-x', $body['purchase_units']['basket'][0]['product_id']);
        self::assertSame(3, $body['purchase_units']['basket'][0]['quantity']);
    }

    public function test_get_order_details_parses_full_response(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
        ])));
        $mock->addResponse(new Response(200, [], json_encode([
            'order_id'          => 'ord-42',
            'order_status'      => ['key' => 'completed', 'value' => 'დასრულებული'],
            'external_order_id' => 'EXT-42',
            'capture'           => 'automatic',
            'purchase_units'    => [
                'currency_code'   => 'GEL',
                'request_amount'  => '100.0',
                'transfer_amount' => '100.0',
                'refund_amount'   => '0.0',
                'items'           => [],
            ],
            'payment_detail'    => [
                'transfer_method' => ['key' => 'card', 'value' => 'ბარათით გადახდა'],
                'transaction_id'  => 'txn-001',
                'payer_identifier' => '411111xxxxxx1111',
            ],
            'buyer'             => [
                'full_name'    => 'Jane Doe',
                'email'        => 'jane@example.com',
                'phone_number' => '+995599000000',
            ],
            'actions'           => [
                [
                    'action_id'         => 'act-1',
                    'action'            => 'authorize',
                    'request_channel'   => 'public_api',
                    'amount'            => '100.0',
                    'status'            => 'completed',
                    'zoned_action_date' => '2026-04-06T10:05:00Z',
                    'code'              => null,
                    'code_description'  => null,
                ],
            ],
            'zoned_create_date' => '2026-04-06T10:00:00Z',
            'zoned_expire_date' => '2026-04-06T10:15:00Z',
        ])));

        $client  = $this->makeClient($mock);
        $details = $client->getOrderDetails('ord-42');

        self::assertSame('ord-42', $details->id);
        self::assertSame(\Bog\Payments\Enum\OrderStatus::Completed, $details->status);
        self::assertSame(\Bog\Payments\Enum\Currency::GEL, $details->currency);
        self::assertSame(100.0, $details->requestedAmount);
        self::assertSame('jane@example.com', $details->buyerEmail);
        self::assertSame('411111xxxxxx1111', $details->maskedCard);
        self::assertSame(\Bog\Payments\Enum\PaymentMethod::Card, $details->paymentMethod);
        self::assertCount(1, $details->actions);
        self::assertSame(\Bog\Payments\Enum\ActionType::Authorize, $details->actions[0]->type);
    }
}
