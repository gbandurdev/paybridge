<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\Cache;

use PayBridge\Store\Cache\CacheIdempotencyStore;
use PayBridge\Store\InMemory\InMemoryCache;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class CacheIdempotencyStoreTest extends TestCase
{
    public function testGetReturnsNullWhenNothingStored(): void
    {
        $store = new CacheIdempotencyStore(new InMemoryCache(new FakeClock()));

        self::assertNull($store->get('idem:stripe:order-1'));
    }

    public function testPutThenGetRoundTripsTheRecord(): void
    {
        $store = new CacheIdempotencyStore(new InMemoryCache(new FakeClock()));

        $store->put('idem:stripe:order-1', ['fingerprint' => 'abc', 'status' => 'completed'], 3600);

        self::assertSame(['fingerprint' => 'abc', 'status' => 'completed'], $store->get('idem:stripe:order-1'));
    }

    public function testRecordExpiresAfterTtl(): void
    {
        $clock = new FakeClock();
        $store = new CacheIdempotencyStore(new InMemoryCache($clock));

        $store->put('idem:stripe:order-1', ['fingerprint' => 'abc', 'status' => 'completed'], 60);
        $clock->advance(61);

        self::assertNull($store->get('idem:stripe:order-1'));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $store = new CacheIdempotencyStore(new InMemoryCache(new FakeClock()));

        $store->put('idem:stripe:order-1', ['fingerprint' => 'a'], 3600);
        $store->put('idem:paypal:order-1', ['fingerprint' => 'b'], 3600);

        self::assertSame(['fingerprint' => 'a'], $store->get('idem:stripe:order-1'));
        self::assertSame(['fingerprint' => 'b'], $store->get('idem:paypal:order-1'));
    }
}
