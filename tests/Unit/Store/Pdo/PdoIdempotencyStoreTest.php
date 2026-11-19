<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\Pdo;

use PayBridge\Store\Pdo\Migrator;
use PayBridge\Store\Pdo\PdoIdempotencyStore;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class PdoIdempotencyStoreTest extends TestCase
{
    public function testGetReturnsNullWhenNothingStored(): void
    {
        $store = new PdoIdempotencyStore($this->pdo(), new FakeClock());

        self::assertNull($store->get('idem:stripe:order-1'));
    }

    public function testPutThenGetRoundTripsTheRecord(): void
    {
        $store = new PdoIdempotencyStore($this->pdo(), new FakeClock());

        $store->put('idem:stripe:order-1', [
            'fingerprint' => str_repeat('a', 64),
            'status' => 'completed',
            'response' => ['providerReference' => 'pay_1'],
        ], 3600);

        $record = $store->get('idem:stripe:order-1');

        self::assertIsArray($record);
        self::assertSame(str_repeat('a', 64), $record['fingerprint']);
        self::assertSame('completed', $record['status']);
        self::assertSame(['providerReference' => 'pay_1'], $record['response']);
        self::assertNull($record['error']);
    }

    public function testPuttingTheSameKeyTwiceUpsertsInsteadOfViolatingTheUniqueConstraint(): void
    {
        $store = new PdoIdempotencyStore($this->pdo(), new FakeClock());

        $store->put('idem:stripe:order-1', ['fingerprint' => str_repeat('a', 64), 'status' => 'failed'], 3600);
        $store->put('idem:stripe:order-1', ['fingerprint' => str_repeat('b', 64), 'status' => 'completed'], 3600);

        $record = $store->get('idem:stripe:order-1');

        self::assertIsArray($record);
        self::assertSame(str_repeat('b', 64), $record['fingerprint']);
        self::assertSame('completed', $record['status']);
    }

    public function testRecordExpiresAfterTtl(): void
    {
        $clock = new FakeClock();
        $store = new PdoIdempotencyStore($this->pdo(), $clock);

        $store->put('idem:stripe:order-1', ['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], 60);
        $clock->advance(61);

        self::assertNull($store->get('idem:stripe:order-1'));
    }

    public function testSameIdempotencyKeyOnDifferentGatewaysAreIndependentRows(): void
    {
        $store = new PdoIdempotencyStore($this->pdo(), new FakeClock());

        $store->put('idem:stripe:order-1', ['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], 3600);
        $store->put('idem:paypal:order-1', ['fingerprint' => str_repeat('b', 64), 'status' => 'completed'], 3600);

        self::assertSame(str_repeat('a', 64), $store->get('idem:stripe:order-1')['fingerprint'] ?? null);
        self::assertSame(str_repeat('b', 64), $store->get('idem:paypal:order-1')['fingerprint'] ?? null);
    }

    public function testPurgeExpiredDeletesOnlyExpiredRowsAndReturnsTheCount(): void
    {
        $clock = new FakeClock();
        $store = new PdoIdempotencyStore($this->pdo(), $clock);

        $store->put('idem:stripe:order-1', ['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], 60);
        $store->put('idem:stripe:order-2', ['fingerprint' => str_repeat('b', 64), 'status' => 'completed'], 3600);
        $clock->advance(61);

        self::assertSame(1, $store->purgeExpired());
        self::assertNull($store->get('idem:stripe:order-1'));
        self::assertNotNull($store->get('idem:stripe:order-2'));
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Migrator::migrate($pdo);

        return $pdo;
    }
}
