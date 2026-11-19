<?php

declare(strict_types=1);

namespace PayBridge\Store\Pdo;

/**
 * Shared helpers for the PDO-backed stores: formatting a DateTimeImmutable for binding, and
 * portably detecting a unique/PRIMARY KEY constraint violation PDOException across the
 * sqlite/mysql/pgsql drivers (SQLSTATE class "23", Integrity Constraint Violation — the only
 * constraint any of these tables define is the one each store's own atomic-upsert INSERT
 * relies on).
 */
trait PdoSupport
{
    private function formatTimestamp(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d H:i:s.u');
    }

    private function isConstraintViolation(\PDOException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();

        return \is_string($sqlState) && str_starts_with($sqlState, '23');
    }
}
