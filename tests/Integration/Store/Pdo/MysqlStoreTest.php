<?php

declare(strict_types=1);

namespace PayBridge\Tests\Integration\Store\Pdo;

use PayBridge\Store\Pdo\Migrator;
use PayBridge\Store\Pdo\PdoEventStore;
use PayBridge\Store\Pdo\PdoIdempotencyStore;
use PayBridge\Store\Pdo\PdoLockStore;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

/**
 * Runs the PDO store contract against a real MySQL server. Skipped (not failed) when
 * PAYBRIDGE_TEST_MYSQL_DSN isn't set or the server isn't reachable — same convention as
 * docs/PLAN.md §11.7's sandbox tests skipping without credentials.
 */
final class MysqlStoreTest extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $dsn = getenv('PAYBRIDGE_TEST_MYSQL_DSN');

        if (!\is_string($dsn) || $dsn === '') {
            self::markTestSkipped('PAYBRIDGE_TEST_MYSQL_DSN is not set.');
        }

        try {
            $pdo = new \PDO($dsn);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('DROP TABLE IF EXISTS paybridge_idempotency');
            $pdo->exec('DROP TABLE IF EXISTS paybridge_locks');
            $pdo->exec('DROP TABLE IF EXISTS paybridge_webhook_events');
            Migrator::migrate($pdo);
        } catch (\PDOException $e) {
            self::markTestSkipped(sprintf('MySQL is not reachable: %s', $e->getMessage()));
        }

        $this->pdo = $pdo;
    }

    public function testIdempotencyStoreRoundTripsAndExpires(): void
    {
        $clock = new FakeClock();
        $store = new PdoIdempotencyStore($this->pdo(), $clock);

        $store->put('idem:stripe:order-1', ['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], 60);
        self::assertNotNull($store->get('idem:stripe:order-1'));

        $clock->advance(61);
        self::assertNull($store->get('idem:stripe:order-1'));
    }

    public function testLockStoreIsAtomicAcrossAcquireAndSteal(): void
    {
        $clock = new FakeClock();
        $store = new PdoLockStore($this->pdo(), $clock);

        $first = $store->acquire('idem:stripe:order-1', 30);
        self::assertNotNull($first);

        $whileHeld = $store->acquire('idem:stripe:order-1', 30);
        self::assertNull($whileHeld);

        $clock->advance(31);
        $second = $store->acquire('idem:stripe:order-1', 30);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
    }

    public function testEventStoreClaimAndRelease(): void
    {
        $store = new PdoEventStore($this->pdo(), new FakeClock());

        self::assertTrue($store->claim('stripe:evt_1', 300));
        self::assertFalse($store->claim('stripe:evt_1', 300));

        $store->release('stripe:evt_1');
        self::assertTrue($store->claim('stripe:evt_1', 300));
    }

    private function pdo(): \PDO
    {
        \assert($this->pdo instanceof \PDO);

        return $this->pdo;
    }
}
