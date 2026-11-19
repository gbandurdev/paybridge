<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Store\Pdo;

use PayBridge\Store\Pdo\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testMigrateCreatesAllThreeTables(): void
    {
        $pdo = $this->sqlite();

        Migrator::migrate($pdo);

        foreach (['paybridge_idempotency', 'paybridge_locks', 'paybridge_webhook_events'] as $table) {
            // Throws if the table doesn't exist.
            $pdo->query(sprintf('SELECT 1 FROM %s LIMIT 1', $table));
        }

        $this->addToAssertionCount(3);
    }

    public function testMigrateIsIdempotent(): void
    {
        $pdo = $this->sqlite();

        Migrator::migrate($pdo);
        Migrator::migrate($pdo);

        $pdo->query('SELECT 1 FROM paybridge_idempotency LIMIT 1');
        $this->addToAssertionCount(1);
    }

    private function sqlite(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
