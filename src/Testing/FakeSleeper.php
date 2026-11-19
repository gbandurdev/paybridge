<?php

declare(strict_types=1);

namespace PayBridge\Testing;

use PayBridge\Resilience\Sleeper;

/**
 * Records every requested sleep instead of actually waiting. When constructed with a
 * {@see FakeClock}, each sleep() call also advances it, so timing-dependent logic (e.g. a
 * lock-wait poll loop) stays internally consistent under test without a manual clock dance.
 */
final class FakeSleeper implements Sleeper
{
    /** @var list<int> */
    private array $calls = [];

    public function __construct(private readonly ?FakeClock $clock = null) {}

    public function sleep(int $milliseconds): void
    {
        $this->calls[] = $milliseconds;
        $this->clock?->advanceMilliseconds($milliseconds);
    }

    /** @return list<int> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function totalMilliseconds(): int
    {
        return array_sum($this->calls);
    }
}
