<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Dto;

use Bog\Payments\Dto\Response\CreateOrderResponse;
use PHPUnit\Framework\TestCase;

final class CreateOrderResponseTest extends TestCase
{
    public function test_parses_standard_order_response(): void
    {
        $response = CreateOrderResponse::fromArray([
            'id'     => 'ord-abc',
            '_links' => [
                'redirect' => ['href' => 'https://payment.bog.ge/?order_id=ord-abc'],
                'details'  => ['href' => 'https://api.bog.ge/payments/v1/receipt/ord-abc'],
            ],
        ]);

        self::assertSame('ord-abc', $response->orderId);
        self::assertSame('https://payment.bog.ge/?order_id=ord-abc', $response->redirectUrl);
        self::assertSame('https://api.bog.ge/payments/v1/receipt/ord-abc', $response->detailsUrl);
        self::assertNull($response->acceptUrl);
        self::assertNull($response->status);
    }

    public function test_parses_apple_pay_response_with_accept_url(): void
    {
        $response = CreateOrderResponse::fromArray([
            'id'     => 'ap-ord',
            '_links' => [
                'accept' => ['href' => 'https://api.bog.ge/payments/v1/ecommerce/orders/ap-ord/payment'],
            ],
        ]);

        self::assertSame('ap-ord', $response->orderId);
        self::assertNull($response->redirectUrl);
        self::assertSame(
            'https://api.bog.ge/payments/v1/ecommerce/orders/ap-ord/payment',
            $response->acceptUrl,
        );
    }

    public function test_parses_google_pay_completed_without_3ds(): void
    {
        // External Google Pay can complete synchronously without a redirect
        $response = CreateOrderResponse::fromArray([
            'id'     => 'gp-ord',
            'status' => 'completed',
            '_links' => [
                'details' => ['href' => 'https://api.bog.ge/payments/v1/receipt/gp-ord'],
            ],
        ]);

        self::assertSame('gp-ord', $response->orderId);
        self::assertNull($response->redirectUrl);
        self::assertNull($response->acceptUrl);
        self::assertSame('completed', $response->status);
        self::assertSame('https://api.bog.ge/payments/v1/receipt/gp-ord', $response->detailsUrl);
    }

    public function test_parses_google_pay_3ds_redirect(): void
    {
        // External Google Pay with 3DS returns a redirect URL
        $response = CreateOrderResponse::fromArray([
            'id'     => 'gp-3ds',
            'status' => 'processing',
            '_links' => [
                'redirect' => ['href' => 'https://payment.bog.ge/api/3ds/post-form?...'],
                'details'  => ['href' => 'https://api.bog.ge/payments/v1/receipt/gp-3ds'],
            ],
        ]);

        self::assertSame('gp-3ds', $response->orderId);
        self::assertSame('https://payment.bog.ge/api/3ds/post-form?...', $response->redirectUrl);
        self::assertSame('processing', $response->status);
        self::assertNull($response->acceptUrl);
    }

    public function test_throws_on_missing_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"id"');
        CreateOrderResponse::fromArray([
            '_links' => ['redirect' => ['href' => 'https://payment.bog.ge']],
        ]);
    }

    public function test_redirect_url_null_when_neither_link_present(): void
    {
        $response = CreateOrderResponse::fromArray([
            'id'     => 'no-links',
            'status' => 'completed',
        ]);

        self::assertNull($response->redirectUrl);
        self::assertNull($response->acceptUrl);
        self::assertSame('completed', $response->status);
    }
}
