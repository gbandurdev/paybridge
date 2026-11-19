<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\InMemory;

use PayBridge\Store\InMemory\InMemoryEventStore;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class InMemoryEventStoreTest extends TestCase
{
    public function testHasIsFalseBeforeRemember(): void
    {
        $store = new InMemoryEventStore(new FakeClock());

        self::assertFalse($store->has('evt_1'));
    }

    public function testHasIsTrueAfterRemember(): void
    {
        $store = new InMemoryEventStore(new FakeClock());

        $store->remember('evt_1', 60);

        self::assertTrue($store->has('evt_1'));
    }

    public function testHasIsFalseAgainAfterTtlPasses(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryEventStore($clock);

        $store->remember('evt_1', 60);
        $clock->advance(61);

        self::assertFalse($store->has('evt_1'));
    }

    public function testClaimSucceedsWhenNothingHoldsTheKey(): void
    {
        $store = new InMemoryEventStore(new FakeClock());

        self::assertTrue($store->claim('evt_1', 300));
        self::assertTrue($store->has('evt_1'));
    }

    public function testClaimFailsWhileAnotherClaimIsStillLive(): void
    {
        $store = new InMemoryEventStore(new FakeClock());

        self::assertTrue($store->claim('evt_1', 300));
        self::assertFalse($store->claim('evt_1', 300));
    }

    public function testClaimSucceedsAgainAfterThePriorClaimExpires(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryEventStore($clock);

        $store->claim('evt_1', 300);
        $clock->advance(301);

        self::assertTrue($store->claim('evt_1', 300));
    }

    public function testReleaseAllowsAnImmediateReclaim(): void
    {
        $store = new InMemoryEventStore(new FakeClock());

        $store->claim('evt_1', 300);
        $store->release('evt_1');

        self::assertFalse($store->has('evt_1'));
        self::assertTrue($store->claim('evt_1', 300));
    }

    public function testRememberAfterClaimExtendsTheSameEntryToTheLongerTtl(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryEventStore($clock);

        $store->claim('evt_1', 300);
        $store->remember('evt_1', 604_800);
        $clock->advance(301);

        // Still remembered — remember() extended the same entry past the short claim TTL.
        self::assertTrue($store->has('evt_1'));
        self::assertFalse($store->claim('evt_1', 300));
    }
}
