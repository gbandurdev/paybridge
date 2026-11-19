<?php

declare(strict_types=1);

namespace PayBridge\Resilience\Backoff;

use PayBridge\Resilience\RandomSource;

/**
 * sleep = random(0, min(maxMs, baseMs * 2^attempt)) — exponential backoff with full jitter.
 */
final class ExponentialJitterBackoff
{
    public function __construct(private readonly RandomSource $random) {}

    public function delayMs(int $attempt, int $baseMs, int $maxMs): int
    {
        $upperBound = min($maxMs, $baseMs * (2 ** $attempt));

        return $this->random->nextInt(0, $upperBound);
    }
}
