<?php

declare(strict_types=1);

namespace PayBridge\Store\InMemory;

use PayBridge\Contracts\Clock;
use PayBridge\Contracts\LockStore;

final class InMemoryLockStore implements LockStore
{
    /** @var array<string, array{token: string, expiresAt: \DateTimeImmutable}> */
    private array $entries = [];

    public function __construct(private readonly Clock $clock) {}

    public function acquire(string $key, int $ttlSeconds): ?string
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry !== null && $this->clock->now() < $entry['expiresAt']) {
            return null;
        }

        $token = bin2hex(random_bytes(16));

        $this->entries[$key] = [
            'token' => $token,
            'expiresAt' => $this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds)),
        ];

        return $token;
    }

    public function release(string $key, string $token): void
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry !== null && hash_equals($entry['token'], $token)) {
            unset($this->entries[$key]);
        }
    }
}
