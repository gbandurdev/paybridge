<?php

declare(strict_types=1);

namespace PayBridge\Testing;

use PayBridge\Contracts\Clock;

/**
 * Deterministic clock for tests: never moves on its own, only via advance()/advanceMilliseconds().
 */
final class FakeClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d seconds', $seconds));
    }

    public function advanceMilliseconds(int $milliseconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d microseconds', $milliseconds * 1000));
    }
}
