<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\Pdo;

use PayBridge\Store\Pdo\Migrator;
use PayBridge\Store\Pdo\PdoEventStore;
use PayBridge\Store\Pdo\PdoIdempotencyStore;
use PayBridge\Store\Pdo\PdoLockStore;
use PayBridge\Store\Pdo\Pruner;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class PrunerTest extends TestCase
{
    public function testPruneAllReturnsPerTableCountsAndLeavesStillValidRowsAlone(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Migrator::migrate($pdo);

        $clock = new FakeClock();
        $idempotency = new PdoIdempotencyStore($pdo, $clock);
        $locks = new PdoLockStore($pdo, $clock);
        $events = new PdoEventStore($pdo, $clock);

        $idempotency->put('idem:stripe:order-1', ['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], 60);
        $idempotency->put('idem:stripe:order-2', ['fingerprint' => str_repeat('b', 64), 'status' => 'completed'], 3600);

        $locks->acquire('idem:stripe:order-1', 60);
        $locks->acquire('idem:stripe:order-2', 3600);

        $events->remember('stripe:evt_1', 60);
        $events->remember('stripe:evt_2', 3600);

        $clock->advance(61);

        $pruner = new Pruner($idempotency, $locks, $events);
        $counts = $pruner->pruneAll();

        self::assertSame(['idempotency' => 1, 'locks' => 1, 'events' => 1], $counts);
        self::assertNull($idempotency->get('idem:stripe:order-1'));
        self::assertNotNull($idempotency->get('idem:stripe:order-2'));
    }
}
