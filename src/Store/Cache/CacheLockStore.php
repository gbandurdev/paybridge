<?php

declare(strict_types=1);

namespace PayBridge\Store\Cache;

use PayBridge\Contracts\LockStore;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache adapter — the zero-infra default lock store (docs/PLAN.md §2).
 *
 * Dev / single-process only. acquire() is a has()-then-set() check, not atomic — an accepted
 * ceiling of PSR-16 itself (no CAS/NX primitive exists in the interface), not a bug specific to
 * this class: two concurrent callers can both observe has() === false and both set(), each
 * believing they hold the lock. Do not use as a production multi-worker lock: use the PDO store
 * (atomic via a UNIQUE constraint) or the Redis store (atomic via SET ... NX) instead. The
 * Laravel and Symfony bridges (Phase 5, see docs/PLAN.md §12) must use their own framework's
 * lock primitive (Cache::lock() / symfony/lock) by default — never this class.
 */
final class CacheLockStore implements LockStore
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'paybridge:lock:',
    ) {}

    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $cacheKey = $this->cacheKey($key);

        if ($this->cache->has($cacheKey)) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $this->cache->set($cacheKey, $token, $ttlSeconds);

        return $token;
    }

    public function release(string $key, string $token): void
    {
        $cacheKey = $this->cacheKey($key);
        $stored = $this->cache->get($cacheKey);

        if (\is_string($stored) && hash_equals($stored, $token)) {
            $this->cache->delete($cacheKey);
        }
    }

    private function cacheKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
