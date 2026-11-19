<?php

declare(strict_types=1);

namespace PayBridge\Store\Pdo;

use PayBridge\Contracts\Clock;
use PayBridge\Contracts\EventStore;

/**
 * PDO-backed EventStore (docs/PLAN.md §12). claim() is genuinely atomic, same upsert idiom as
 * PdoLockStore: a plain INSERT wins via the PRIMARY KEY on a fresh key; a unique-violation falls
 * back to a conditional UPDATE that only succeeds if the existing claim has already expired.
 */
final class PdoEventStore implements EventStore
{
    use PdoSupport;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function has(string $eventKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM paybridge_webhook_events WHERE event_key = :key AND expires_at > :now');
        $stmt->execute(['key' => $eventKey, 'now' => $this->formatTimestamp($this->clock->now())]);

        return $stmt->fetchColumn() !== false;
    }

    public function remember(string $eventKey, int $ttlSeconds): void
    {
        $expiresAt = $this->formatTimestamp($this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds)));

        try {
            $stmt = $this->pdo->prepare('INSERT INTO paybridge_webhook_events (event_key, expires_at) VALUES (:key, :expires_at)');
            $stmt->execute(['key' => $eventKey, 'expires_at' => $expiresAt]);

            return;
        } catch (\PDOException $e) {
            if (!$this->isConstraintViolation($e)) {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare('UPDATE paybridge_webhook_events SET expires_at = :expires_at WHERE event_key = :key');
        $stmt->execute(['expires_at' => $expiresAt, 'key' => $eventKey]);
    }

    public function claim(string $eventKey, int $ttlSeconds): bool
    {
        $expiresAt = $this->formatTimestamp($this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds)));

        try {
            $stmt = $this->pdo->prepare('INSERT INTO paybridge_webhook_events (event_key, expires_at) VALUES (:key, :expires_at)');
            $stmt->execute(['key' => $eventKey, 'expires_at' => $expiresAt]);

            return true;
        } catch (\PDOException $e) {
            if (!$this->isConstraintViolation($e)) {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE paybridge_webhook_events SET expires_at = :expires_at WHERE event_key = :key AND expires_at <= :now',
        );
        $stmt->execute([
            'expires_at' => $expiresAt,
            'key' => $eventKey,
            'now' => $this->formatTimestamp($this->clock->now()),
        ]);

        return $stmt->rowCount() === 1;
    }

    public function release(string $eventKey): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM paybridge_webhook_events WHERE event_key = :key');
        $stmt->execute(['key' => $eventKey]);
    }

    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM paybridge_webhook_events WHERE expires_at <= :now');
        $stmt->execute(['now' => $this->formatTimestamp($this->clock->now())]);

        return $stmt->rowCount();
    }
}
