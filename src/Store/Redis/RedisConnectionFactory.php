<?php

declare(strict_types=1);

namespace PayBridge\Store\Redis;

/**
 * Parses the redis://host:port DSN format from docs/PLAN.md §5's example into a connected
 * \Redis client. Uses ext-redis directly rather than predis/predis — see docs/plans/phase-4.md
 * §7 for why.
 */
final class RedisConnectionFactory
{
    public static function fromDsn(string $dsn): \Redis
    {
        $parts = parse_url($dsn);

        if (!\is_array($parts) || ($parts['scheme'] ?? null) !== 'redis') {
            throw new \InvalidArgumentException(sprintf('Invalid Redis DSN: "%s".', $dsn));
        }

        $redis = new \Redis();
        $redis->connect($parts['host'] ?? '127.0.0.1', $parts['port'] ?? 6379);

        if (isset($parts['pass'])) {
            $redis->auth($parts['pass']);
        }

        $database = trim($parts['path'] ?? '', '/');

        if ($database !== '' && ctype_digit($database)) {
            $redis->select((int) $database);
        }

        return $redis;
    }
}
