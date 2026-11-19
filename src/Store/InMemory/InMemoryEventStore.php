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
        return $this->isLive($eventKey);
    }

    public function remember(string $eventKey, int $ttlSeconds): void
    {
        $this->expiresAt[$eventKey] = $this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds));
    }

    public function claim(string $eventKey, int $ttlSeconds): bool
    {
        if ($this->isLive($eventKey)) {
            return false;
        }

        $this->remember($eventKey, $ttlSeconds);

        return true;
    }

    public function release(string $eventKey): void
    {
        unset($this->expiresAt[$eventKey]);
    }

    private function isLive(string $eventKey): bool
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
}
