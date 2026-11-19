<?php

declare(strict_types=1);

namespace PayBridge\Store\Redis;

use PayBridge\Contracts\LockStore;

/**
 * Redis-backed LockStore (docs/PLAN.md §12), the standard atomic distributed-lock pattern:
 * SET key token NX PX ttlMs for acquire, and a compare-and-delete Lua script for release — a
 * plain DEL would delete a lock someone else now legitimately owns after ours expired, the same
 * race InMemoryLockStore/PdoLockStore already close via their own token checks.
 */
final class RedisLockStore implements LockStore
{
    private const string RELEASE_SCRIPT = <<<'LUA'
        if redis.call("GET", KEYS[1]) == ARGV[1] then
            return redis.call("DEL", KEYS[1])
        else
            return 0
        end
        LUA;

    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'paybridge:lock:',
    ) {}

    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $token = bin2hex(random_bytes(16));
        $ok = $this->redis->set($this->cacheKey($key), $token, ['NX', 'PX' => $ttlSeconds * 1000]);

        return $ok ? $token : null;
    }

    public function release(string $key, string $token): void
    {
        $this->redis->eval(self::RELEASE_SCRIPT, [$this->cacheKey($key), $token], 1);
    }

    private function cacheKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
