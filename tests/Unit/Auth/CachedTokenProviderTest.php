<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Auth;

use Bog\Payments\Auth\AccessToken;
use Bog\Payments\Auth\CachedTokenProvider;
use Bog\Payments\Auth\TokenFetcherInterface;
use Bog\Payments\BogConfig;
use Bog\Payments\Cache\InMemoryCache;
use Bog\Payments\Exception\AuthenticationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CachedTokenProviderTest extends TestCase
{
    private BogConfig $config;
    private InMemoryCache $cache;

    /** @var TokenFetcherInterface&MockObject */
    private TokenFetcherInterface $inner;

    protected function setUp(): void
    {
        $this->config = new BogConfig('cid', 'secret', ttlBufferSeconds: 30);
        $this->cache  = new InMemoryCache();
        $this->inner  = $this->createMock(TokenFetcherInterface::class);
    }

    private function makeProvider(): CachedTokenProvider
    {
        return new CachedTokenProvider($this->inner, $this->cache, $this->config);
    }

    public function test_cache_miss_calls_inner_provider(): void
    {
        $this->inner
            ->expects($this->once())
            ->method('fetchToken')
            ->willReturn(new AccessToken('fresh-token', 3600));

        $provider = $this->makeProvider();
        self::assertSame('fresh-token', $provider->getToken());
    }

    public function test_cache_hit_does_not_call_inner(): void
    {
        $this->inner
            ->expects($this->never())
            ->method('fetchToken');

        // Pre-populate cache
        $this->cache->set('bog_oauth_access_token', 'cached-token', 3600);

        $provider = $this->makeProvider();
        self::assertSame('cached-token', $provider->getToken());
    }

    public function test_token_cached_with_ttl_buffer_applied(): void
    {
        $this->inner
            ->method('fetchToken')
            ->willReturn(new AccessToken('tok', expiresIn: 3600));

        $provider = $this->makeProvider();
        $provider->getToken();

        // After caching, a second call must return from cache (inner not called again)
        $this->inner
            ->expects($this->never())
            ->method('fetchToken');

        self::assertSame('tok', $provider->getToken());
    }

    public function test_expired_cache_refreshes(): void
    {
        // Store with very short TTL (0 effectively) → cache treats as expired immediately
        $this->cache->set('bog_oauth_access_token', 'old-token', -1);

        $this->inner
            ->expects($this->once())
            ->method('fetchToken')
            ->willReturn(new AccessToken('new-token', 3600));

        $provider = $this->makeProvider();
        self::assertSame('new-token', $provider->getToken());
    }

    public function test_invalidate_and_refresh_deletes_cache_and_fetches_new(): void
    {
        $this->cache->set('bog_oauth_access_token', 'stale-token', 3600);

        $this->inner
            ->expects($this->once())
            ->method('fetchToken')
            ->willReturn(new AccessToken('refreshed-token', 3600));

        $provider = $this->makeProvider();
        $result   = $provider->invalidateAndRefresh();

        self::assertSame('refreshed-token', $result);
    }

    public function test_inner_exception_propagates(): void
    {
        $this->inner
            ->method('fetchToken')
            ->willThrowException(new AuthenticationException('bad creds'));

        $provider = $this->makeProvider();

        $this->expectException(AuthenticationException::class);
        $provider->getToken();
    }

    public function test_short_expires_in_with_buffer_does_not_store(): void
    {
        // expires_in (10) < ttlBufferSeconds (30) → TTL would be negative → must not store
        $this->inner
            ->method('fetchToken')
            ->willReturn(new AccessToken('tok', expiresIn: 10)); // 10 - 30 = -20

        $provider = $this->makeProvider();
        $provider->getToken();

        // Cache should not have been set (TTL <= 0)
        self::assertNull($this->cache->get('bog_oauth_access_token'));
    }
}
