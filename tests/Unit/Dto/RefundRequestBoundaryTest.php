<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Dto;

use Bog\Payments\Dto\Request\RefundRequest;
use PHPUnit\Framework\TestCase;

/**
 * Boundary tests for RefundRequest amount guard.
 * Kills LessThanOrEqualTo → LessThan mutation on `$amount <= 0`.
 */
final class RefundRequestBoundaryTest extends TestCase
{
    public function test_zero_amount_throws(): void
    {
        // Mutation: `<= 0` → `< 0` would allow 0.0 through without throwing.
        $this->expectException(\InvalidArgumentException::class);
        new RefundRequest(amount: 0.0);
    }

    public function test_negative_amount_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RefundRequest(amount: -0.01);
    }

    public function test_positive_amount_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        new RefundRequest(amount: 0.01);
    }

    public function test_null_amount_full_refund_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        new RefundRequest(amount: null);
    }
}
