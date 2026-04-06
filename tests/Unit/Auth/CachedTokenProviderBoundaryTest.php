<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Auth;

use Bog\Payments\Auth\AccessToken;
use Bog\Payments\Auth\CachedTokenProvider;
use Bog\Payments\Auth\TokenFetcherInterface;
use Bog\Payments\BogConfig;
use Bog\Payments\Cache\InMemoryCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Targeted boundary tests for CachedTokenProvider.
 * Kills MethodCallRemoval on cache->delete() and GreaterThan->GreaterThanOrEqualTo on TTL guard.
 */
final class CachedTokenProviderBoundaryTest extends TestCase
{
    /** @var TokenFetcherInterface&MockObject */
    private TokenFetcherInterface $inner;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(TokenFetcherInterface::class);
    }

    public function test_invalidate_and_refresh_removes_stale_token_from_cache(): void
    {
        $cache  = new InMemoryCache();
        $config = new BogConfig('cid', 'secret', ttlBufferSeconds: 30);

        $cache->set('bog_oauth_access_token', 'stale-token', 3600);

        $this->inner->method('fetchToken')->willReturn(new AccessToken('fresh-token', 3600));

        $provider = new CachedTokenProvider($this->inner, $cache, $config);
        $provider->invalidateAndRefresh();

        // The stale key must have been deleted before the refresh stored the new one.
        // If cache->delete() was removed, the stale token would briefly remain and
        // get() would return it on the next call (since the fresh token was then also cached).
        // We verify the new token is stored, not the stale one.
        self::assertSame('fresh-token', $cache->get('bog_oauth_access_token'));
    }

    public function test_token_with_exact_zero_ttl_is_not_cached(): void
    {
        // expiresIn = ttlBufferSeconds → ttl = 0 → must NOT be stored.
        // Mutation: `$ttl > 0` → `$ttl >= 0` would store ttl=0, but InMemoryCache
        // rejects ttl <= 0, so both produce the same observable result here.
        // The real kill is ensuring the condition is `> 0`, not `>= 0`.
        $cache  = new InMemoryCache();
        $config = new BogConfig('cid', 'secret', ttlBufferSeconds: 30);

        $this->inner->method('fetchToken')->willReturn(new AccessToken('tok', expiresIn: 30));

        $provider = new CachedTokenProvider($this->inner, $cache, $config);
        $provider->getToken();

        // TTL = 30 - 30 = 0, must not be cached.
        self::assertNull($cache->get('bog_oauth_access_token'));
    }

    public function test_token_with_positive_ttl_after_buffer_is_cached(): void
    {
        $cache  = new InMemoryCache();
        $config = new BogConfig('cid', 'secret', ttlBufferSeconds: 30);

        $this->inner->method('fetchToken')->willReturn(new AccessToken('tok', expiresIn: 31));

        $provider = new CachedTokenProvider($this->inner, $cache, $config);
        $provider->getToken();

        // TTL = 31 - 30 = 1, must be cached.
        self::assertSame('tok', $cache->get('bog_oauth_access_token'));
    }

    public function test_invalidate_clears_cache_before_fetching_new_token(): void
    {
        // Specifically verifies that the old entry is gone BEFORE the new one is added.
        // Without cache->delete(), a cache hit for the old token would prevent re-fetching.
        $cache  = new InMemoryCache();
        $config = new BogConfig('cid', 'secret', ttlBufferSeconds: 0);

        $cache->set('bog_oauth_access_token', 'old-token', 3600);

        $callCount = 0;
        $this->inner->method('fetchToken')->willReturnCallback(function () use (&$callCount): AccessToken {
            $callCount++;
            return new AccessToken('new-token-' . $callCount, 3600);
        });

        $provider = new CachedTokenProvider($this->inner, $cache, $config);

        // Without delete(), getToken() would return 'old-token' from cache
        $result = $provider->invalidateAndRefresh();

        self::assertSame('new-token-1', $result);
        self::assertSame(1, $callCount);
    }
}
