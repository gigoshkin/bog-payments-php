<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Enum;

use Bog\Payments\Enum\Currency;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function test_from_valid_values(): void
    {
        self::assertSame(Currency::GEL, Currency::from('GEL'));
        self::assertSame(Currency::USD, Currency::from('USD'));
        self::assertSame(Currency::EUR, Currency::from('EUR'));
        self::assertSame(Currency::GBP, Currency::from('GBP'));
    }

    public function test_from_invalid_throws(): void
    {
        $this->expectException(\ValueError::class);
        Currency::from('XXX');
    }

    public function test_try_from_invalid_returns_null(): void
    {
        self::assertNull(Currency::tryFrom('INVALID'));
        self::assertNull(Currency::tryFrom(''));
        self::assertNull(Currency::tryFrom('gel')); // case-sensitive
    }

    public function test_try_from_valid(): void
    {
        self::assertSame(Currency::GEL, Currency::tryFrom('GEL'));
    }

    public function test_all_cases_covered(): void
    {
        $cases = Currency::cases();
        self::assertCount(4, $cases);
    }
}
