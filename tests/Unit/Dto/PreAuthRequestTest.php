<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Dto;

use Bog\Payments\Dto\Request\CancelPreAuthRequest;
use Bog\Payments\Dto\Request\ConfirmPreAuthRequest;
use PHPUnit\Framework\TestCase;

final class PreAuthRequestTest extends TestCase
{
    // -------------------------------------------------------------------------
    // CancelPreAuthRequest::toArray()
    // -------------------------------------------------------------------------

    public function test_cancel_with_description_includes_it(): void
    {
        $req = new CancelPreAuthRequest('fraud detected');
        self::assertSame(['description' => 'fraud detected'], $req->toArray());
    }

    public function test_cancel_without_description_returns_empty_array(): void
    {
        $req = new CancelPreAuthRequest();
        self::assertSame([], $req->toArray());
    }

    // -------------------------------------------------------------------------
    // ConfirmPreAuthRequest::toArray()
    // -------------------------------------------------------------------------

    public function test_confirm_with_amount_only(): void
    {
        $req = new ConfirmPreAuthRequest(amount: 75.50);
        $data = $req->toArray();

        self::assertSame(75.50, $data['amount']);
        self::assertArrayNotHasKey('description', $data);
    }

    public function test_confirm_with_description_only(): void
    {
        $req = new ConfirmPreAuthRequest(description: 'partial ship');
        $data = $req->toArray();

        self::assertSame('partial ship', $data['description']);
        self::assertArrayNotHasKey('amount', $data);
    }

    public function test_confirm_with_both_amount_and_description(): void
    {
        $req = new ConfirmPreAuthRequest(amount: 100.0, description: 'full capture');
        $data = $req->toArray();

        self::assertSame(100.0, $data['amount']);
        self::assertSame('full capture', $data['description']);
    }

    public function test_confirm_with_neither_returns_empty_array(): void
    {
        $req = new ConfirmPreAuthRequest();
        self::assertSame([], $req->toArray());
    }

    public function test_confirm_throws_on_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConfirmPreAuthRequest(amount: 0.0);
    }

    public function test_confirm_throws_on_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConfirmPreAuthRequest(amount: -5.0);
    }
}
