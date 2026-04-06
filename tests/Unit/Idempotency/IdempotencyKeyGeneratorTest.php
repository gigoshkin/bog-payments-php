<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Idempotency;

use Bog\Payments\Idempotency\IdempotencyKeyGenerator;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyGeneratorTest extends TestCase
{
    private IdempotencyKeyGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new IdempotencyKeyGenerator();
    }

    public function test_generates_valid_uuid_v4_format(): void
    {
        $key = $this->generator->generate();

        // RFC 4122 UUID v4: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        self::assertMatchesRegularExpression($pattern, $key);
    }

    public function test_generates_unique_keys(): void
    {
        $keys = array_map(fn() => $this->generator->generate(), range(1, 100));
        self::assertCount(100, array_unique($keys));
    }

    public function test_key_has_correct_version_bits(): void
    {
        $key   = $this->generator->generate();
        $parts = explode('-', $key);
        // Third segment must start with '4'
        self::assertStringStartsWith('4', $parts[2]);
    }

    public function test_key_has_correct_variant_bits(): void
    {
        $key   = $this->generator->generate();
        $parts = explode('-', $key);
        // Fourth segment must start with 8, 9, a, or b
        self::assertMatchesRegularExpression('/^[89ab]/i', $parts[3]);
    }
}
