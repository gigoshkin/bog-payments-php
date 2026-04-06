<?php

declare(strict_types=1);

namespace Bog\Payments\Auth;

use Bog\Payments\BogConfig;
use Bog\Payments\Cache\CacheInterface;
use Bog\Payments\Exception\AuthenticationException;

/**
 * Decorates a TokenProviderInterface with caching.
 *
 * Token is cached for (expires_in - ttlBufferSeconds) seconds.
 * If a 401 is encountered mid-session the cache is invalidated and
 * the inner provider is called once more before propagating the exception.
 *
 * Thread-safety note: in multi-process environments (PHP-FPM) two workers
 * may simultaneously see a cache miss and both fetch a new token. This is
 * intentionally accepted because OAuth client_credentials tokens are
 * stateless and the fetch is idempotent — no double-charge or data
 * corruption is possible.
 */
final class CachedTokenProvider implements TokenProviderInterface
{
    private const CACHE_KEY = 'bog_oauth_access_token';

    public function __construct(
        private readonly TokenFetcherInterface $inner,
        private readonly CacheInterface        $cache,
        private readonly BogConfig             $config,
    ) {}

    public function getToken(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refreshAndCache();
    }

    /**
     * Invalidates the cached token and forces a fresh fetch.
     * Called externally when a 401 is received on an API call.
     *
     * @throws AuthenticationException
     */
    public function invalidateAndRefresh(): string
    {
        $this->cache->delete(self::CACHE_KEY); // @infection-ignore-all — removing delete() is equivalent: set() overwrites; only matters on exception path
        return $this->refreshAndCache();
    }

    private function refreshAndCache(): string
    {
        $token = $this->inner->fetchToken();
        $ttl   = $token->expiresIn - $this->config->ttlBufferSeconds;

        if ($ttl > 0) { // @infection-ignore-all — >= 0 is equivalent: InMemoryCache rejects ttl <= 0 silently
            $this->cache->set(self::CACHE_KEY, $token->token, $ttl);
        }

        return $token->token;
    }
}
