<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Dto;

use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Dto\Request\SplitTransfer;
use Bog\Payments\Enum\CaptureMode;
use Bog\Payments\Enum\Currency;
use Bog\Payments\Enum\PaymentMethod;
use PHPUnit\Framework\TestCase;

final class CreateOrderRequestTest extends TestCase
{
    private function makeBasket(): array
    {
        return [new BasketItem('prod-1', 2, 50.0, 'Widget')];
    }

    public function test_to_array_contains_required_fields(): void
    {
        $request = new CreateOrderRequest(
            callbackUrl:  'https://example.com/callback',
            totalAmount:  100.0,
            basket:       $this->makeBasket(),
        );

        $data = $request->toArray();

        self::assertSame('https://example.com/callback', $data['callback_url']);
        self::assertSame(100.0, $data['purchase_units']['total_amount']);
        self::assertCount(1, $data['purchase_units']['basket']);
        self::assertSame('GEL', $data['currency']);
        self::assertSame('automatic', $data['capture']);
    }

    public function test_optional_fields_absent_when_null(): void
    {
        $request = new CreateOrderRequest(
            callbackUrl: 'https://example.com/callback',
            totalAmount: 10.0,
            basket:      $this->makeBasket(),
        );

        $data = $request->toArray();

        self::assertArrayNotHasKey('external_order_id', $data);
        self::assertArrayNotHasKey('redirect_url', $data);
        self::assertArrayNotHasKey('ttl', $data);
        self::assertArrayNotHasKey('payment_method', $data);
    }

    public function test_external_order_id_included_when_set(): void
    {
        $request = new CreateOrderRequest(
            callbackUrl:     'https://example.com/callback',
            totalAmount:     10.0,
            basket:          $this->makeBasket(),
            externalOrderId: 'EXT-001',
        );

        self::assertSame('EXT-001', $request->toArray()['external_order_id']);
    }

    public function test_payment_methods_serialised_as_values(): void
    {
        $request = new CreateOrderRequest(
            callbackUrl:    'https://example.com/callback',
            totalAmount:    10.0,
            basket:         $this->makeBasket(),
            paymentMethods: [PaymentMethod::Card, PaymentMethod::ApplePay],
        );

        $data = $request->toArray();
        self::assertSame(['card', 'apple_pay'], $data['payment_method']);
    }

    public function test_split_transfers_serialised(): void
    {
        $request = new CreateOrderRequest(
            callbackUrl:    'https://example.com/callback',
            totalAmount:    100.0,
            basket:         $this->makeBasket(),
            splitTransfers: [new SplitTransfer('GE00000000000000000001', 50.0)],
        );

        $data = $request->toArray();
        self::assertCount(1, $data['config']['split']['transfers']);
        self::assertSame('GE00000000000000000001', $data['config']['split']['transfers'][0]['iban']);
    }

    public function test_save_card_flag_serialised(): void
    {
        $request = new CreateOrderRequest(
            callbackUrl: 'https://example.com/callback',
            totalAmount: 10.0,
            basket:      $this->makeBasket(),
            saveCard:    true,
        );

        self::assertTrue($request->toArray()['config']['save_card']);
    }

    public function test_manual_capture_serialised(): void
    {
        $request = new CreateOrderRequest(
            callbackUrl: 'https://example.com/callback',
            totalAmount: 10.0,
            basket:      $this->makeBasket(),
            capture:     CaptureMode::Manual,
        );

        self::assertSame('manual', $request->toArray()['capture']);
    }

    public function test_throws_on_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateOrderRequest('https://example.com/cb', 0.0, $this->makeBasket());
    }

    public function test_throws_on_empty_basket(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateOrderRequest('https://example.com/cb', 10.0, []);
    }

    public function test_throws_on_invalid_ttl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CreateOrderRequest('https://example.com/cb', 10.0, $this->makeBasket(), ttl: 9999);
    }

    public function test_throws_on_too_many_split_transfers(): void
    {
        $transfers = array_fill(0, 11, new SplitTransfer('GE00000000000000000001', 1.0));

        $this->expectException(\InvalidArgumentException::class);
        new CreateOrderRequest('https://example.com/cb', 10.0, $this->makeBasket(), splitTransfers: $transfers);
    }

    public function test_exactly_10_split_transfers_does_not_throw(): void
    {
        // Mutation: count > 10 → count >= 10 would throw here and fail the test.
        $this->expectNotToPerformAssertions();
        $transfers = array_fill(0, 10, new SplitTransfer('GE00000000000000000001', 1.0));
        new CreateOrderRequest('https://example.com/cb', 10.0, $this->makeBasket(), splitTransfers: $transfers);
    }

    public function test_save_card_false_by_default_not_in_output(): void
    {
        // Mutation: saveCard default false → true would include save_card in config output.
        $data = (new CreateOrderRequest(
            callbackUrl: 'https://example.com/cb',
            totalAmount: 10.0,
            basket:      $this->makeBasket(),
        ))->toArray();

        self::assertArrayNotHasKey('config', $data);
    }
}
