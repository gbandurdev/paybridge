<?php

declare(strict_types=1);

namespace PayBridge\Store\Pdo;

use PayBridge\Contracts\Clock;
use PayBridge\Contracts\LockStore;

/**
 * PDO-backed LockStore (docs/PLAN.md §12). acquire() is genuinely atomic: a plain INSERT wins
 * via the PRIMARY KEY on a fresh key; a unique-violation falls back to a single conditional
 * UPDATE that only affects the row if it's actually expired, with rowCount() telling this
 * caller whether it won the steal-an-expired-lock race.
 */
final class PdoLockStore implements LockStore
{
    use PdoSupport;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $token = bin2hex(random_bytes(16));
        $expiresAt = $this->formatTimestamp($this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds)));

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO paybridge_locks (lock_key, token, expires_at) VALUES (:key, :token, :expires_at)',
            );
            $stmt->execute(['key' => $key, 'token' => $token, 'expires_at' => $expiresAt]);

            return $token;
        } catch (\PDOException $e) {
            if (!$this->isConstraintViolation($e)) {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE paybridge_locks SET token = :token, expires_at = :expires_at WHERE lock_key = :key AND expires_at <= :now',
        );
        $stmt->execute([
            'token' => $token,
            'expires_at' => $expiresAt,
            'key' => $key,
            'now' => $this->formatTimestamp($this->clock->now()),
        ]);

        return $stmt->rowCount() === 1 ? $token : null;
    }

    public function release(string $key, string $token): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM paybridge_locks WHERE lock_key = :key AND token = :token');
        $stmt->execute(['key' => $key, 'token' => $token]);
    }

    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM paybridge_locks WHERE expires_at <= :now');
        $stmt->execute(['now' => $this->formatTimestamp($this->clock->now())]);

        return $stmt->rowCount();
    }
}
