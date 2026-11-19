<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store;

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
use PayBridge\Store\Pdo\PdoEventStore;
use PayBridge\Store\Pdo\PdoIdempotencyStore;
use PayBridge\Store\Pdo\PdoLockStore;
use PayBridge\Store\Redis\RedisEventStore;
use PayBridge\Store\Redis\RedisIdempotencyStore;
use PayBridge\Store\Redis\RedisLockStore;
use PayBridge\Store\StoreFactory;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class StoreFactoryTest extends TestCase
{
    public function testDefaultsToInMemoryWhenNoDriverGiven(): void
    {
        $factory = new StoreFactory(new FakeClock());

        self::assertInstanceOf(InMemoryIdempotencyStore::class, $factory->idempotency([]));
        self::assertInstanceOf(InMemoryLockStore::class, $factory->lock([]));
        self::assertInstanceOf(InMemoryEventStore::class, $factory->events([]));
        self::assertInstanceOf(InMemoryCache::class, $factory->cache([]));
    }

    public function testCacheDriverBuildsCacheBackedStoresFromTheGivenPsr16Instance(): void
    {
        $factory = new StoreFactory(new FakeClock());
        $cache = new InMemoryCache(new FakeClock());
        $config = ['driver' => 'cache', 'cache' => $cache];

        self::assertInstanceOf(CacheIdempotencyStore::class, $factory->idempotency($config));
        self::assertInstanceOf(CacheLockStore::class, $factory->lock($config));
        self::assertInstanceOf(CacheEventStore::class, $factory->events($config));
        self::assertSame($cache, $factory->cache($config));
    }

    public function testCacheDriverWithoutACacheInstanceThrows(): void
    {
        $factory = new StoreFactory(new FakeClock());

        $this->expectException(\InvalidArgumentException::class);

        $factory->idempotency(['driver' => 'cache']);
    }

    public function testPdoDriverBuildsPdoBackedStoresAndReusesOneConnectionPerDsn(): void
    {
        $factory = new StoreFactory(new FakeClock());
        $config = ['driver' => 'pdo', 'dsn' => 'sqlite::memory:'];

        $idempotency = $factory->idempotency($config);
        $lock = $factory->lock($config);
        $events = $factory->events($config);

        self::assertInstanceOf(PdoIdempotencyStore::class, $idempotency);
        self::assertInstanceOf(PdoLockStore::class, $lock);
        self::assertInstanceOf(PdoEventStore::class, $events);

        // One shared connection per DSN: a record written via one store is visible to another
        // built from the same $config (would be two independent :memory: databases otherwise).
        $idempotency->put('idem:stripe:order-1', ['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], 3600);
        $again = $factory->idempotency($config);
        self::assertNotNull($again->get('idem:stripe:order-1'));
    }

    public function testPdoDriverWithoutADsnThrows(): void
    {
        $factory = new StoreFactory(new FakeClock());

        $this->expectException(\InvalidArgumentException::class);

        $factory->idempotency(['driver' => 'pdo']);
    }

    public function testRedisDriverWithoutADsnThrows(): void
    {
        $factory = new StoreFactory(new FakeClock());

        $this->expectException(\InvalidArgumentException::class);

        $factory->idempotency(['driver' => 'redis']);
    }

    public function testRedisDriverBuildsRedisBackedStores(): void
    {
        if (!\extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not installed.');
        }

        $factory = new StoreFactory(new FakeClock());
        $config = ['driver' => 'redis', 'dsn' => 'redis://127.0.0.1:6379'];

        try {
            $idempotency = $factory->idempotency($config);
        } catch (\Throwable $e) {
            self::markTestSkipped(sprintf('Redis is not reachable: %s', $e->getMessage()));
        }

        self::assertInstanceOf(RedisIdempotencyStore::class, $idempotency);
        self::assertInstanceOf(RedisLockStore::class, $factory->lock($config));
        self::assertInstanceOf(RedisEventStore::class, $factory->events($config));
    }

    public function testUnsupportedIdempotencyDriverThrows(): void
    {
        $factory = new StoreFactory(new FakeClock());

        $this->expectException(\InvalidArgumentException::class);

        $factory->idempotency(['driver' => 'mongodb']);
    }

    public function testUnsupportedLockDriverThrows(): void
    {
        $factory = new StoreFactory(new FakeClock());

        $this->expectException(\InvalidArgumentException::class);

        $factory->lock(['driver' => 'mongodb']);
    }

    public function testUnsupportedEventsDriverThrows(): void
    {
        $factory = new StoreFactory(new FakeClock());

        $this->expectException(\InvalidArgumentException::class);

        $factory->events(['driver' => 'mongodb']);
    }

    public function testCacheRejectsPdoAndRedisDriversSinceTheBreakerNeedsAPsr16Instance(): void
    {
        $factory = new StoreFactory(new FakeClock());

        $this->expectException(\InvalidArgumentException::class);

        $factory->cache(['driver' => 'pdo', 'dsn' => 'sqlite::memory:']);
    }

    public function testBuiltStoresSatisfyTheirContractInterfaces(): void
    {
        $factory = new StoreFactory(new FakeClock());

        self::assertInstanceOf(IdempotencyStore::class, $factory->idempotency([]));
        self::assertInstanceOf(LockStore::class, $factory->lock([]));
        self::assertInstanceOf(EventStore::class, $factory->events([]));
        self::assertInstanceOf(CacheInterface::class, $factory->cache([]));
    }
}
