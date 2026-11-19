<?php

declare(strict_types=1);

namespace PayBridge\Store\Pdo;

/**
 * Deletes expired rows from the three PDO-backed stores. Reads already filter out expired rows
 * (WHERE expires_at > ?), so correctness never depends on this running — it's housekeeping,
 * meant to run from cron like Reconciliation\Reconciler: it owns no scheduler itself.
 */
final class Pruner
{
    public function __construct(
        private readonly PdoIdempotencyStore $idempotency,
        private readonly PdoLockStore $locks,
        private readonly PdoEventStore $events,
    ) {}

    /**
     * @return array{idempotency: int, locks: int, events: int}
     */
    public function pruneAll(): array
    {
        return [
            'idempotency' => $this->idempotency->purgeExpired(),
            'locks' => $this->locks->purgeExpired(),
            'events' => $this->events->purgeExpired(),
        ];
    }
}
