<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

interface LockStore
{
    /**
     * Returns a random opaque owner token on success, or null if the lock is already held.
     */
    public function acquire(string $key, int $ttlSeconds): ?string;

    /**
     * Deletes the lock only if its currently-stored token still matches $token; a release
     * carrying a stale or foreign token (e.g. from an expired lock since re-acquired by
     * someone else) is a silent no-op.
     */
    public function release(string $key, string $token): void;
}
