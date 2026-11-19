<?php

declare(strict_types=1);

namespace PayBridge\Store\InMemory;

use PayBridge\Contracts\Clock;
use PayBridge\Contracts\EventStore;

final class InMemoryEventStore implements EventStore
{
    /** @var array<string, \DateTimeImmutable> */
    private array $expiresAt = [];

    public function __construct(private readonly Clock $clock) {}

    public function has(string $eventKey): bool
    {
        $expiresAt = $this->expiresAt[$eventKey] ?? null;

        if ($expiresAt === null) {
            return false;
        }

        if ($this->clock->now() >= $expiresAt) {
            unset($this->expiresAt[$eventKey]);

            return false;
        }

        return true;
    }

    public function remember(string $eventKey, int $ttlSeconds): void
    {
        $this->expiresAt[$eventKey] = $this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds));
    }
}
