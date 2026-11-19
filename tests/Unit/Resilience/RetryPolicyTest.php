<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Resilience;

use PayBridge\Domain\Capability;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\NetworkException;
use PayBridge\Exception\ProviderUnavailable;
use PayBridge\Exception\RateLimitedException;
use PayBridge\Resilience\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testRetryableUnderMaxAttemptsOnFindIsRetried(): void
    {
        $result = RetryPolicy::shouldRetry(new NetworkException('x'), attempt: 1, maxAttempts: 3, operationKind: 'find', capabilities: []);

        self::assertTrue($result);
    }

    public function testNonRetryableIsNeverRetriedRegardlessOfAttemptCount(): void
    {
        self::assertFalse(RetryPolicy::shouldRetry(new CardDeclined('x'), attempt: 1, maxAttempts: 10, operationKind: 'find', capabilities: []));
    }

    public function testRetryableButAttemptsExhaustedIsNotRetried(): void
    {
        $result = RetryPolicy::shouldRetry(new NetworkException('x'), attempt: 3, maxAttempts: 3, operationKind: 'find', capabilities: []);

        self::assertFalse($result);
    }

    public function testMutatingWithoutProviderIdempotencyStillRetriesRateLimited(): void
    {
        $result = RetryPolicy::shouldRetry(new RateLimitedException('x'), attempt: 1, maxAttempts: 3, operationKind: 'charge', capabilities: []);

        self::assertTrue($result);
    }

    public function testMutatingWithoutProviderIdempotencyStillRetriesProviderUnavailable(): void
    {
        $result = RetryPolicy::shouldRetry(new ProviderUnavailable('x'), attempt: 1, maxAttempts: 3, operationKind: 'charge', capabilities: []);

        self::assertTrue($result);
    }

    public function testMutatingWithoutProviderIdempotencyStillRetriesNonAmbiguousNetworkException(): void
    {
        $result = RetryPolicy::shouldRetry(new NetworkException('x', ambiguous: false), attempt: 1, maxAttempts: 3, operationKind: 'charge', capabilities: []);

        self::assertTrue($result);
    }

    public function testMutatingWithoutProviderIdempotencyBlocksAmbiguousNetworkException(): void
    {
        $result = RetryPolicy::shouldRetry(new NetworkException('x', ambiguous: true), attempt: 1, maxAttempts: 3, operationKind: 'charge', capabilities: []);

        self::assertFalse($result);
    }

    public function testMutatingWithProviderIdempotencyAllowsAmbiguousNetworkException(): void
    {
        $result = RetryPolicy::shouldRetry(
            new NetworkException('x', ambiguous: true),
            attempt: 1,
            maxAttempts: 3,
            operationKind: 'charge',
            capabilities: [Capability::PROVIDER_IDEMPOTENCY],
        );

        self::assertTrue($result);
    }

    public function testAmbiguousGateOnlyAppliesToMutatingOperations(): void
    {
        $result = RetryPolicy::shouldRetry(new NetworkException('x', ambiguous: true), attempt: 1, maxAttempts: 3, operationKind: 'find', capabilities: []);

        self::assertTrue($result);
    }

    public function testCaptureRefundAndVoidAreAllTreatedAsMutating(): void
    {
        $exception = new NetworkException('x', ambiguous: true);

        foreach (['capture', 'refund', 'void'] as $operation) {
            self::assertFalse(
                RetryPolicy::shouldRetry($exception, attempt: 1, maxAttempts: 3, operationKind: $operation, capabilities: []),
                sprintf('Expected "%s" to be gated like a mutating operation.', $operation),
            );
        }
    }
}
