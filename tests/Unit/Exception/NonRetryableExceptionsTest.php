<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Exception;

use PayBridge\Exception\AuthenticationFailed;
use PayBridge\Exception\InvalidRequest;
use PayBridge\Exception\InvalidSignature;
use PayBridge\Exception\PaymentException;
use PayBridge\Exception\RetryableException;
use PayBridge\Exception\UnsupportedOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonRetryableExceptionsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<PaymentException>}>
     */
    public static function nonRetryableClasses(): iterable
    {
        yield AuthenticationFailed::class => [AuthenticationFailed::class];
        yield InvalidRequest::class => [InvalidRequest::class];
        yield InvalidSignature::class => [InvalidSignature::class];
        yield UnsupportedOperation::class => [UnsupportedOperation::class];
        // CircuitOpen is NOT here (Phase 2): it implements RetryableException so
        // IdempotencyMiddleware treats a breaker trip like any other transient failure
        // instead of permanently caching it — see RetryableExceptionsTest.
    }

    /**
     * @param class-string<PaymentException> $class
     */
    #[DataProvider('nonRetryableClasses')]
    public function testIsNotRetryable(string $class): void
    {
        $exception = new $class('message', gateway: 'stripe', operation: 'charge');

        self::assertNotInstanceOf(RetryableException::class, $exception);
        self::assertFalse($exception->retryable());
        self::assertSame('stripe', $exception->gateway());
        self::assertSame('charge', $exception->operation());
    }
}
