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

    /**
     * Atomically claims $eventKey for $ttlSeconds if nothing currently holds it. Returns true
     * if this call won the claim (caller must process the event), false if it's already
     * claimed (caller must treat it as a duplicate and skip processing).
     *
     * Intended to be claimed first with a short "processing" TTL, then — only once processing
     * actually succeeds — extended via {@see self::remember()} to the long-term dedup TTL. That
     * way a worker that crashes mid-processing (never reaching remember() or release()) only
     * blocks a real redelivery for the short claim TTL, not the full dedup window.
     */
    public function claim(string $eventKey, int $ttlSeconds): bool;

    /**
     * Releases a claim early (e.g. the caller's own processing failed) so a future redelivery
     * of the same event can be claimed and retried instead of being treated as a duplicate.
     */
    public function release(string $eventKey): void;
}
