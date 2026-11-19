<?php

declare(strict_types=1);

namespace PayBridge\Testing;

use PayBridge\Resilience\RandomSource;

/**
 * Deterministic RandomSource for tests: forces nextInt() to a chosen edge of its range
 * instead of a real random draw, so backoff bounds can be asserted exactly.
 */
final class FakeRandomSource implements RandomSource
{
    /** @param \Closure(int, int): int $strategy */
    private function __construct(private readonly \Closure $strategy) {}

    public static function alwaysMax(): self
    {
        return new self(static fn(int $min, int $max): int => $max);
    }

    public static function alwaysMin(): self
    {
        return new self(static fn(int $min, int $max): int => $min);
    }

    public static function fixed(int $value): self
    {
        return new self(static fn(int $min, int $max): int => $value);
    }

    public function nextInt(int $min, int $max): int
    {
        return ($this->strategy)($min, $max);
    }
}
