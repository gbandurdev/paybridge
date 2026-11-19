<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\InMemory;

use PayBridge\Store\InMemory\InMemoryIdempotencyStore;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class InMemoryIdempotencyStoreTest extends TestCase
{
    public function testPutThenGetRoundTrips(): void
    {
        $store = new InMemoryIdempotencyStore(new FakeClock());

        $store->put('key', ['status' => 'completed'], 60);

        self::assertSame(['status' => 'completed'], $store->get('key'));
    }

    public function testGetOnMissingKeyIsNull(): void
    {
        $store = new InMemoryIdempotencyStore(new FakeClock());

        self::assertNull($store->get('missing'));
    }

    public function testRecordExpiresAfterTtlElapses(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryIdempotencyStore($clock);

        $store->put('key', ['status' => 'completed'], 60);
        $clock->advance(61);

        self::assertNull($store->get('key'));
    }

    public function testRecordReadJustBeforeExpiryStillReturns(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryIdempotencyStore($clock);

        $store->put('key', ['status' => 'completed'], 60);
        $clock->advance(59);

        self::assertSame(['status' => 'completed'], $store->get('key'));
    }

    public function testPutOnExistingKeyOverwritesValueAndResetsExpiry(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryIdempotencyStore($clock);

        $store->put('key', ['status' => 'first'], 10);
        $clock->advance(9);
        $store->put('key', ['status' => 'second'], 60);
        $clock->advance(20);

        self::assertSame(['status' => 'second'], $store->get('key'));
    }
}
