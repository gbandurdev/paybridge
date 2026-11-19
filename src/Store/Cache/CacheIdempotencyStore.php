<?php

declare(strict_types=1);

namespace PayBridge\Store\Cache;

use PayBridge\Contracts\IdempotencyStore;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache adapter — the zero-infra default idempotency store (docs/PLAN.md §2). Plain
 * get()/set(); PSR-16's own TTL handling does the expiry work, no Clock needed.
 */
final class CacheIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'paybridge:idem:',
    ) {}

    public function get(string $key): ?array
    {
        $value = $this->cache->get($this->cacheKey($key));

        if (!\is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function put(string $key, array $record, int $ttlSeconds): void
    {
        $this->cache->set($this->cacheKey($key), $record, $ttlSeconds);
    }

    private function cacheKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
