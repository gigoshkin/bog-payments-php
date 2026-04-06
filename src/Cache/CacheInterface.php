<?php

declare(strict_types=1);

namespace Bog\Payments\Cache;

interface CacheInterface
{
    /**
     * Retrieve a cached value. Returns null on cache miss or expired entry.
     */
    public function get(string $key): mixed;

    /**
     * Store a value in cache with a TTL in seconds.
     * A TTL of 0 or less means the value expires immediately (never stored).
     */
    public function set(string $key, mixed $value, int $ttl): void;

    /**
     * Remove a cached value.
     */
    public function delete(string $key): void;
}
