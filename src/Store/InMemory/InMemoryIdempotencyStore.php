<?php

declare(strict_types=1);

namespace PayBridge\Store\InMemory;

use PayBridge\Contracts\Clock;
use PayBridge\Contracts\IdempotencyStore;

final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{record: array<string, mixed>, expiresAt: \DateTimeImmutable}> */
    private array $entries = [];

    public function __construct(private readonly Clock $clock) {}

    public function get(string $key): ?array
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($this->clock->now() >= $entry['expiresAt']) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['record'];
    }

    public function put(string $key, array $record, int $ttlSeconds): void
    {
        $this->entries[$key] = [
            'record' => $record,
            'expiresAt' => $this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds)),
        ];
    }
}
