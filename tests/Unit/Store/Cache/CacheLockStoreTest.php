<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\Cache;

use PayBridge\Store\Cache\CacheLockStore;
use PayBridge\Store\InMemory\InMemoryCache;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class CacheLockStoreTest extends TestCase
{
    public function testAcquireReturnsATokenWhenFree(): void
    {
        $store = new CacheLockStore(new InMemoryCache(new FakeClock()));

        self::assertNotNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testAcquireReturnsNullWhileAlreadyHeld(): void
    {
        $store = new CacheLockStore(new InMemoryCache(new FakeClock()));

        $store->acquire('idem:stripe:order-1', 30);

        self::assertNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testAcquireSucceedsAgainAfterTheHeldLockExpires(): void
    {
        $clock = new FakeClock();
        $store = new CacheLockStore(new InMemoryCache($clock));

        $store->acquire('idem:stripe:order-1', 30);
        $clock->advance(31);

        self::assertNotNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testReleaseWithTheCorrectTokenFreesTheLock(): void
    {
        $store = new CacheLockStore(new InMemoryCache(new FakeClock()));

        $token = $store->acquire('idem:stripe:order-1', 30);
        self::assertNotNull($token);

        $store->release('idem:stripe:order-1', $token);

        self::assertNotNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testReleaseWithAStaleOrForeignTokenIsANoOp(): void
    {
        $store = new CacheLockStore(new InMemoryCache(new FakeClock()));

        $store->acquire('idem:stripe:order-1', 30);
        $store->release('idem:stripe:order-1', 'not-the-real-token');

        self::assertNull($store->acquire('idem:stripe:order-1', 30));
    }
}
