<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\Pdo;

use PayBridge\Store\Pdo\Migrator;
use PayBridge\Store\Pdo\PdoEventStore;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class PdoEventStoreTest extends TestCase
{
    public function testHasIsFalseBeforeRemember(): void
    {
        $store = new PdoEventStore($this->pdo(), new FakeClock());

        self::assertFalse($store->has('stripe:evt_1'));
    }

    public function testHasIsTrueAfterRemember(): void
    {
        $store = new PdoEventStore($this->pdo(), new FakeClock());

        $store->remember('stripe:evt_1', 60);

        self::assertTrue($store->has('stripe:evt_1'));
    }

    public function testHasIsFalseAgainAfterTtlPasses(): void
    {
        $clock = new FakeClock();
        $store = new PdoEventStore($this->pdo(), $clock);

        $store->remember('stripe:evt_1', 60);
        $clock->advance(61);

        self::assertFalse($store->has('stripe:evt_1'));
    }

    public function testClaimSucceedsWhenNothingHoldsTheKeyAndFailsOnASecondAttempt(): void
    {
        $store = new PdoEventStore($this->pdo(), new FakeClock());

        self::assertTrue($store->claim('stripe:evt_1', 300));
        self::assertFalse($store->claim('stripe:evt_1', 300));
    }

    public function testClaimSucceedsAgainAfterThePriorClaimExpires(): void
    {
        $clock = new FakeClock();
        $store = new PdoEventStore($this->pdo(), $clock);

        $store->claim('stripe:evt_1', 300);
        $clock->advance(301);

        self::assertTrue($store->claim('stripe:evt_1', 300));
    }

    public function testReleaseAllowsAnImmediateReclaim(): void
    {
        $store = new PdoEventStore($this->pdo(), new FakeClock());

        $store->claim('stripe:evt_1', 300);
        $store->release('stripe:evt_1');

        self::assertTrue($store->claim('stripe:evt_1', 300));
    }

    public function testRememberAfterClaimExtendsTheSameRowPastTheShortClaimTtl(): void
    {
        $clock = new FakeClock();
        $store = new PdoEventStore($this->pdo(), $clock);

        $store->claim('stripe:evt_1', 300);
        $store->remember('stripe:evt_1', 604_800);
        $clock->advance(301);

        self::assertTrue($store->has('stripe:evt_1'));
        self::assertFalse($store->claim('stripe:evt_1', 300));
    }

    public function testPurgeExpiredDeletesOnlyExpiredRowsAndReturnsTheCount(): void
    {
        $clock = new FakeClock();
        $store = new PdoEventStore($this->pdo(), $clock);

        $store->remember('stripe:evt_1', 60);
        $store->remember('stripe:evt_2', 3600);
        $clock->advance(61);

        self::assertSame(1, $store->purgeExpired());
        self::assertFalse($store->has('stripe:evt_1'));
        self::assertTrue($store->has('stripe:evt_2'));
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Migrator::migrate($pdo);

        return $pdo;
    }
}
