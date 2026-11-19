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
}
