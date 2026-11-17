<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

/**
 * Webhook event-id deduplication store.
 */
interface EventStore
{
    public function has(string $eventKey): bool;

    public function remember(string $eventKey, int $ttlSeconds): void;
}
