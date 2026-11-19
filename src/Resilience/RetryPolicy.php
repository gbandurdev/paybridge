<?php

declare(strict_types=1);

namespace PayBridge\Resilience;

use PayBridge\Domain\Capability;
use PayBridge\Exception\NetworkException;
use PayBridge\Exception\RetryableException;

final class RetryPolicy
{
    /** @var list<string> */
    private const MUTATING_OPERATIONS = ['charge', 'capture', 'refund', 'void'];

    /**
     * @param list<Capability> $capabilities
     */
    public static function shouldRetry(
        \Throwable $e,
        int $attempt,
        int $maxAttempts,
        string $operationKind,
        array $capabilities,
    ): bool {
        if (!$e instanceof RetryableException) {
            return false;
        }

        if ($attempt >= $maxAttempts) {
            return false;
        }

        $mutating = \in_array($operationKind, self::MUTATING_OPERATIONS, true);

        if ($mutating && !\in_array(Capability::PROVIDER_IDEMPOTENCY, $capabilities, true)) {
            // Only an *ambiguous* NetworkException is blocked: that's the one case where a
            // blind retry could double-charge with no provider-side dedup to catch it.
            // RateLimitedException, ProviderUnavailable, and non-ambiguous NetworkException
            // are safe to retry even without provider idempotency support.
            if ($e instanceof NetworkException && $e->ambiguous()) {
                return false;
            }
        }

        return true;
    }
}
