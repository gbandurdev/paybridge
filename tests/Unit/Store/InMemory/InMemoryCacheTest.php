<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\InMemory;

use PayBridge\Store\InMemory\InMemoryCache;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class InMemoryCacheTest extends TestCase
{
    public function testSetThenGetRoundTrips(): void
    {
        $cache = new InMemoryCache(new FakeClock());

        $cache->set('key', ['foo' => 'bar']);

        self::assertSame(['foo' => 'bar'], $cache->get('key'));
    }

    public function testGetOnMissingKeyReturnsTheDefault(): void
    {
        $cache = new InMemoryCache(new FakeClock());

        self::assertSame('fallback', $cache->get('missing', 'fallback'));
        self::assertNull($cache->get('missing'));
    }

    public function testTtlExpiryReturnsDefaultAgain(): void
    {
        $clock = new FakeClock();
        $cache = new InMemoryCache($clock);

        $cache->set('key', 'value', 60);
        $clock->advance(61);

        self::assertNull($cache->get('key'));
        self::assertFalse($cache->has('key'));
    }

    public function testNullTtlNeverExpires(): void
    {
        $clock = new FakeClock();
        $cache = new InMemoryCache($clock);

        $cache->set('key', 'value');
        $clock->advance(1_000_000);

        self::assertSame('value', $cache->get('key'));
    }

    public function testDeleteRemovesTheEntry(): void
    {
        $cache = new InMemoryCache(new FakeClock());

        $cache->set('key', 'value');
        $cache->delete('key');

        self::assertFalse($cache->has('key'));
    }

    public function testSetMultipleThenGetMultipleRoundTrips(): void
    {
        $cache = new InMemoryCache(new FakeClock());

        $cache->setMultiple(['a' => 1, 'b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2, 'c' => 'default'], $cache->getMultiple(['a', 'b', 'c'], 'default'));
    }

    public function testDeleteMultipleRemovesEachKey(): void
    {
        $cache = new InMemoryCache(new FakeClock());

        $cache->setMultiple(['a' => 1, 'b' => 2]);
        $cache->deleteMultiple(['a', 'b']);

        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function testClearRemovesEverything(): void
    {
        $cache = new InMemoryCache(new FakeClock());

        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->clear();

        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function testDateIntervalTtlIsHonored(): void
    {
        $clock = new FakeClock();
        $cache = new InMemoryCache($clock);

        $cache->set('key', 'value', new \DateInterval('PT60S'));
        $clock->advance(61);

        self::assertFalse($cache->has('key'));
    }
}
