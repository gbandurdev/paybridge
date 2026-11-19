<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\Pdo;

use PayBridge\Store\Pdo\Migrator;
use PayBridge\Store\Pdo\PdoLockStore;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class PdoLockStoreTest extends TestCase
{
    public function testAcquireReturnsATokenWhenFree(): void
    {
        $store = new PdoLockStore($this->pdo(), new FakeClock());

        self::assertNotNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testAcquireReturnsNullWhileAlreadyHeld(): void
    {
        $store = new PdoLockStore($this->pdo(), new FakeClock());

        $store->acquire('idem:stripe:order-1', 30);

        self::assertNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testAcquireStealsAnExpiredLockAtomically(): void
    {
        $clock = new FakeClock();
        $store = new PdoLockStore($this->pdo(), $clock);

        $firstToken = $store->acquire('idem:stripe:order-1', 30);
        self::assertNotNull($firstToken);

        $clock->advance(31);

        $secondToken = $store->acquire('idem:stripe:order-1', 30);

        self::assertNotNull($secondToken);
        self::assertNotSame($firstToken, $secondToken);
    }

    public function testReleaseWithTheCorrectTokenFreesTheLock(): void
    {
        $store = new PdoLockStore($this->pdo(), new FakeClock());

        $token = $store->acquire('idem:stripe:order-1', 30);
        self::assertNotNull($token);

        $store->release('idem:stripe:order-1', $token);

        self::assertNotNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testReleaseWithAStaleOrForeignTokenIsANoOp(): void
    {
        $store = new PdoLockStore($this->pdo(), new FakeClock());

        $store->acquire('idem:stripe:order-1', 30);
        $store->release('idem:stripe:order-1', 'not-the-real-token');

        self::assertNull($store->acquire('idem:stripe:order-1', 30));
    }

    public function testPurgeExpiredDeletesOnlyExpiredRowsAndReturnsTheCount(): void
    {
        $clock = new FakeClock();
        $store = new PdoLockStore($this->pdo(), $clock);

        $store->acquire('idem:stripe:order-1', 30);
        $store->acquire('idem:stripe:order-2', 3600);
        $clock->advance(31);

        self::assertSame(1, $store->purgeExpired());
        self::assertNotNull($store->acquire('idem:stripe:order-1', 30));
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Migrator::migrate($pdo);

        return $pdo;
    }
}
