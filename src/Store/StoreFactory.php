<?php

declare(strict_types=1);

namespace PayBridge\Store;

use PayBridge\Contracts\Clock;
use PayBridge\Contracts\EventStore;
use PayBridge\Contracts\IdempotencyStore;
use PayBridge\Contracts\LockStore;
use PayBridge\Store\Cache\CacheEventStore;
use PayBridge\Store\Cache\CacheIdempotencyStore;
use PayBridge\Store\Cache\CacheLockStore;
use PayBridge\Store\InMemory\InMemoryCache;
use PayBridge\Store\InMemory\InMemoryEventStore;
use PayBridge\Store\InMemory\InMemoryIdempotencyStore;
use PayBridge\Store\InMemory\InMemoryLockStore;
use PayBridge\Store\Pdo\Migrator;
use PayBridge\Store\Pdo\PdoEventStore;
use PayBridge\Store\Pdo\PdoIdempotencyStore;
use PayBridge\Store\Pdo\PdoLockStore;
use PayBridge\Store\Redis\RedisConnectionFactory;
use PayBridge\Store\Redis\RedisEventStore;
use PayBridge\Store\Redis\RedisIdempotencyStore;
use PayBridge\Store\Redis\RedisLockStore;
use Psr\SimpleCache\CacheInterface;

/**
 * Builds a store from one PaymentManager::fromConfig() 'stores.*' entry
 * (driver: inmemory|cache|pdo|redis). One instance per fromConfig() call — not static state
 * (docs/PLAN.md §13): it memoizes PDO/\Redis connections by DSN for the lifetime of that one
 * call, so idempotency/lock/events sharing the same DSN share one real connection (and one
 * Migrator::migrate() run) instead of opening three.
 */
final class StoreFactory
{
    /** @var array<string, \PDO> */
    private array $pdoConnections = [];

    /** @var array<string, \Redis> */
    private array $redisConnections = [];

    public function __construct(private readonly Clock $clock) {}

    /**
     * @param array<string, mixed> $config
     */
    public function idempotency(array $config): IdempotencyStore
    {
        return match ($this->driver($config)) {
            'inmemory' => new InMemoryIdempotencyStore($this->clock),
            'cache' => new CacheIdempotencyStore($this->requireCache($config)),
            'pdo' => new PdoIdempotencyStore($this->pdo($config), $this->clock),
            'redis' => new RedisIdempotencyStore($this->redis($config)),
            default => throw $this->unsupportedDriver($config, 'idempotency'),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public function lock(array $config): LockStore
    {
        return match ($this->driver($config)) {
            'inmemory' => new InMemoryLockStore($this->clock),
            'cache' => new CacheLockStore($this->requireCache($config)),
            'pdo' => new PdoLockStore($this->pdo($config), $this->clock),
            'redis' => new RedisLockStore($this->redis($config)),
            default => throw $this->unsupportedDriver($config, 'lock'),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public function events(array $config): EventStore
    {
        return match ($this->driver($config)) {
            'inmemory' => new InMemoryEventStore($this->clock),
            'cache' => new CacheEventStore($this->requireCache($config)),
            'pdo' => new PdoEventStore($this->pdo($config), $this->clock),
            'redis' => new RedisEventStore($this->redis($config)),
            default => throw $this->unsupportedDriver($config, 'events'),
        };
    }

    /**
     * Backs the circuit breaker's shared state (docs/PLAN.md §6.4). Only 'inmemory' or 'cache'
     * are accepted — the breaker needs a Psr\SimpleCache\CacheInterface, and 'pdo'/'redis' don't
     * have one lying around without building a bespoke adapter nothing else in this phase needs.
     *
     * @param array<string, mixed> $config
     */
    public function cache(array $config): CacheInterface
    {
        return match ($this->driver($config)) {
            'inmemory' => new InMemoryCache($this->clock),
            'cache' => $this->requireCache($config),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported cache store driver "%s": the circuit breaker needs an "inmemory" or "cache" (PSR-16) store.',
                $this->driver($config),
            )),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function driver(array $config): string
    {
        $driver = $config['driver'] ?? 'inmemory';

        return \is_string($driver) ? $driver : 'inmemory';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requireCache(array $config): CacheInterface
    {
        $cache = $config['cache'] ?? null;

        if (!$cache instanceof CacheInterface) {
            throw new \InvalidArgumentException('The "cache" store driver requires a "cache" key holding a Psr\SimpleCache\CacheInterface instance.');
        }

        return $cache;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pdo(array $config): \PDO
    {
        $dsn = $config['dsn'] ?? null;

        if (!\is_string($dsn) || $dsn === '') {
            throw new \InvalidArgumentException('The "pdo" store driver requires a "dsn" key.');
        }

        if (!isset($this->pdoConnections[$dsn])) {
            $pdo = new \PDO($dsn);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            Migrator::migrate($pdo);
            $this->pdoConnections[$dsn] = $pdo;
        }

        return $this->pdoConnections[$dsn];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function redis(array $config): \Redis
    {
        $dsn = $config['dsn'] ?? null;

        if (!\is_string($dsn) || $dsn === '') {
            throw new \InvalidArgumentException('The "redis" store driver requires a "dsn" key.');
        }

        return $this->redisConnections[$dsn] ??= RedisConnectionFactory::fromDsn($dsn);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function unsupportedDriver(array $config, string $purpose): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf(
            'Unsupported %s store driver "%s"; expected one of inmemory|cache|pdo|redis.',
            $purpose,
            $this->driver($config),
        ));
    }
}
