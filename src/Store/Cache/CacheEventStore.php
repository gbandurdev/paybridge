<?php

declare(strict_types=1);

namespace PayBridge\Store\Cache;

use PayBridge\Contracts\EventStore;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache adapter — the zero-infra default webhook-event dedup store (docs/PLAN.md §2).
 *
 * claim() is a has()-then-set() check — the same non-atomic ceiling as CacheLockStore (PSR-16
 * has no compare-and-set/add-if-absent primitive). Unlike CacheLockStore this isn't a mutual-
 * exclusion lock: the worst case of two concurrent claims both winning is at most one duplicate
 * processing attempt, not a correctness-critical double charge — still, production deployments
 * that need real concurrent-delivery protection should use the PDO or Redis store instead.
 */
final class CacheEventStore implements EventStore
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'paybridge:event:',
    ) {}

    public function has(string $eventKey): bool
    {
        return $this->cache->has($this->cacheKey($eventKey));
    }

    public function remember(string $eventKey, int $ttlSeconds): void
    {
        $this->cache->set($this->cacheKey($eventKey), true, $ttlSeconds);
    }

    public function claim(string $eventKey, int $ttlSeconds): bool
    {
        if ($this->cache->has($this->cacheKey($eventKey))) {
            return false;
        }

        $this->remember($eventKey, $ttlSeconds);

        return true;
    }

    public function release(string $eventKey): void
    {
        $this->cache->delete($this->cacheKey($eventKey));
    }

    private function cacheKey(string $eventKey): string
    {
        return $this->prefix . $eventKey;
    }
}
