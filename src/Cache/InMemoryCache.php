<?php

declare(strict_types=1);

namespace Bog\Payments\Cache;

/**
 * Simple array-backed in-process cache.
 * Suitable for CLI scripts, tests, and single-process applications.
 * For multi-process environments (FPM, RoadRunner) use a shared cache
 * (Redis, Memcached) by implementing CacheInterface.
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires_at: int}> */
    private array $store = [];

    public function get(string $key): mixed
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        if (time() >= $this->store[$key]['expires_at']) { // @infection-ignore-all
            unset($this->store[$key]);
            return null;
        }

        return $this->store[$key]['value'];
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        if ($ttl <= 0) { // @infection-ignore-all
            return; // @infection-ignore-all
        }

        $this->store[$key] = [
            'value'      => $value,
            'expires_at' => time() + $ttl,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
}
