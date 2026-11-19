<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\Cache;

use PayBridge\Store\Cache\CacheEventStore;
use PayBridge\Store\InMemory\InMemoryCache;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class CacheEventStoreTest extends TestCase
{
    public function testHasIsFalseBeforeRemember(): void
    {
        $store = new CacheEventStore(new InMemoryCache(new FakeClock()));

        self::assertFalse($store->has('stripe:evt_1'));
    }

    public function testHasIsTrueAfterRemember(): void
    {
        $store = new CacheEventStore(new InMemoryCache(new FakeClock()));

        $store->remember('stripe:evt_1', 60);

        self::assertTrue($store->has('stripe:evt_1'));
    }

    public function testHasIsFalseAgainAfterTtlPasses(): void
    {
        $clock = new FakeClock();
        $store = new CacheEventStore(new InMemoryCache($clock));

        $store->remember('stripe:evt_1', 60);
        $clock->advance(61);

        self::assertFalse($store->has('stripe:evt_1'));
    }

    public function testClaimSucceedsWhenNothingHoldsTheKeyAndFailsOnASecondAttempt(): void
    {
        $store = new CacheEventStore(new InMemoryCache(new FakeClock()));

        self::assertTrue($store->claim('stripe:evt_1', 300));
        self::assertFalse($store->claim('stripe:evt_1', 300));
    }

    public function testReleaseAllowsAnImmediateReclaim(): void
    {
        $store = new CacheEventStore(new InMemoryCache(new FakeClock()));

        $store->claim('stripe:evt_1', 300);
        $store->release('stripe:evt_1');

        self::assertTrue($store->claim('stripe:evt_1', 300));
    }

    public function testRememberAfterClaimExtendsTheSameEntryPastTheShortClaimTtl(): void
    {
        $clock = new FakeClock();
        $store = new CacheEventStore(new InMemoryCache($clock));

        $store->claim('stripe:evt_1', 300);
        $store->remember('stripe:evt_1', 604_800);
        $clock->advance(301);

        self::assertTrue($store->has('stripe:evt_1'));
        self::assertFalse($store->claim('stripe:evt_1', 300));
    }
}
