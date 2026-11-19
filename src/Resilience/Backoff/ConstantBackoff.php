<?php

declare(strict_types=1);

namespace PayBridge\Resilience\Backoff;

/**
 * Fixed delay, regardless of attempt number — used to honor a provider's Retry-After.
 */
final class ConstantBackoff
{
    public function delayMs(int $fixedMs): int
    {
        return $fixedMs;
    }
}
