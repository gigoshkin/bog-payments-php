<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Cache;

use Bog\Payments\Cache\InMemoryCache;
use PHPUnit\Framework\TestCase;

final class InMemoryCacheTest extends TestCase
{
    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemoryCache();
    }

    public function test_set_and_get_returns_stored_value(): void
    {
        $this->cache->set('key', 'value', 60);
        self::assertSame('value', $this->cache->get('key'));
    }

    public function test_get_returns_null_on_miss(): void
    {
        self::assertNull($this->cache->get('missing'));
    }

    public function test_get_returns_null_after_ttl_expires(): void
    {
        $this->cache->set('key', 'value', -1);
        self::assertNull($this->cache->get('key'));
    }

    public function test_zero_ttl_is_not_stored(): void
    {
        $this->cache->set('key', 'value', 0);
        self::assertNull($this->cache->get('key'));
    }

    public function test_delete_removes_key(): void
    {
        $this->cache->set('key', 'value', 60);
        $this->cache->delete('key');
        self::assertNull($this->cache->get('key'));
    }

    public function test_delete_on_nonexistent_key_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        $this->cache->delete('nonexistent');
    }

    public function test_overwrite_updates_value(): void
    {
        $this->cache->set('key', 'first', 60);
        $this->cache->set('key', 'second', 60);
        self::assertSame('second', $this->cache->get('key'));
    }

    public function test_stores_various_types(): void
    {
        $this->cache->set('array', ['a' => 1], 60);
        $this->cache->set('int', 42, 60);
        $this->cache->set('null_val', null, 60);

        self::assertSame(['a' => 1], $this->cache->get('array'));
        self::assertSame(42, $this->cache->get('int'));
        // null is stored but indistinguishable from a miss — by design (CacheInterface contract)
    }

    public function test_multiple_independent_keys(): void
    {
        $this->cache->set('a', 'alpha', 60);
        $this->cache->set('b', 'beta', 60);

        self::assertSame('alpha', $this->cache->get('a'));
        self::assertSame('beta', $this->cache->get('b'));

        $this->cache->delete('a');

        self::assertNull($this->cache->get('a'));
        self::assertSame('beta', $this->cache->get('b'));
    }
}
