<?php

declare(strict_types=1);

namespace PayBridge\Store\Redis;

use PayBridge\Contracts\EventStore;

final class RedisEventStore implements EventStore
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'paybridge:event:',
    ) {}

    public function has(string $eventKey): bool
    {
        return $this->redis->exists($this->cacheKey($eventKey)) > 0;
    }

    public function remember(string $eventKey, int $ttlSeconds): void
    {
        $this->redis->set($this->cacheKey($eventKey), '1', ['EX' => $ttlSeconds]);
    }

    public function claim(string $eventKey, int $ttlSeconds): bool
    {
        return (bool) $this->redis->set($this->cacheKey($eventKey), '1', ['NX', 'EX' => $ttlSeconds]);
    }

    public function release(string $eventKey): void
    {
        $this->redis->del($this->cacheKey($eventKey));
    }

    private function cacheKey(string $eventKey): string
    {
        return $this->prefix . $eventKey;
    }
}
