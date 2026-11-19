<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\InMemory;

use PayBridge\Store\InMemory\InMemoryLockStore;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class InMemoryLockStoreTest extends TestCase
{
    public function testAcquireOnFreeKeySucceedsAndBlocksASecondAcquire(): void
    {
        $store = new InMemoryLockStore(new FakeClock());

        $token = $store->acquire('key', 30);

        self::assertNotNull($token);
        self::assertNull($store->acquire('key', 30));
    }

    public function testReleaseWithCorrectTokenThenReacquireSucceeds(): void
    {
        $store = new InMemoryLockStore(new FakeClock());

        $token = $store->acquire('key', 30);
        self::assertNotNull($token);
        $store->release('key', $token);

        self::assertNotNull($store->acquire('key', 30));
    }

    public function testStaleLockSelfHealsAfterTtlPassesWithoutRelease(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryLockStore($clock);

        $store->acquire('key', 30);
        $clock->advance(31);

        self::assertNotNull($store->acquire('key', 30));
    }

    public function testReleaseWithWrongTokenIsASilentNoOpAndLeavesCurrentHolderIntact(): void
    {
        $clock = new FakeClock();
        $store = new InMemoryLockStore($clock);

        $tokenA = $store->acquire('key', 30);
        self::assertNotNull($tokenA);

        // A's lock expires; B acquires the now-free key and gets a *different* token.
        $clock->advance(31);
        $tokenB = $store->acquire('key', 30);
        self::assertNotNull($tokenB);
        self::assertNotSame($tokenA, $tokenB);

        // A's late release (unaware of B) must not delete B's lock.
        $store->release('key', $tokenA);

        self::assertNull($store->acquire('key', 30), 'B\'s lock must still be held after A\'s stale release.');

        // B can still release its own, correct token.
        $store->release('key', $tokenB);
        self::assertNotNull($store->acquire('key', 30));
    }

    public function testReleasingAnUnheldKeyIsASilentNoOp(): void
    {
        $store = new InMemoryLockStore(new FakeClock());

        $store->release('never-acquired', 'whatever-token');

        self::assertNotNull($store->acquire('never-acquired', 30));
    }

    public function testTwoDifferentKeysDoNotInterfere(): void
    {
        $store = new InMemoryLockStore(new FakeClock());

        self::assertNotNull($store->acquire('a', 30));
        self::assertNotNull($store->acquire('b', 30));
    }
}
