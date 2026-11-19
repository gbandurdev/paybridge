<?php

declare(strict_types=1);

namespace PayBridge\Store\Redis;

use PayBridge\Contracts\IdempotencyStore;

final class RedisIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'paybridge:idem:',
    ) {}

    public function get(string $key): ?array
    {
        $value = $this->redis->get($this->cacheKey($key));

        if (!\is_string($value)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function put(string $key, array $record, int $ttlSeconds): void
    {
        $this->redis->set($this->cacheKey($key), json_encode($record, \JSON_THROW_ON_ERROR), ['EX' => $ttlSeconds]);
    }

    private function cacheKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
