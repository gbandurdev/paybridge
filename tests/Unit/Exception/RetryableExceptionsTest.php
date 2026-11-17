<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Exception;

use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\NetworkException;
use PayBridge\Exception\ProviderUnavailable;
use PayBridge\Exception\RateLimitedException;
use PayBridge\Exception\RetryableException;
use PHPUnit\Framework\TestCase;

final class RetryableExceptionsTest extends TestCase
{
    public function testNetworkExceptionIsRetryable(): void
    {
        $exception = new NetworkException('timed out', gateway: 'stripe', operation: 'charge');

        self::assertInstanceOf(RetryableException::class, $exception);
        self::assertTrue($exception->retryable());
        self::assertSame('stripe', $exception->gateway());
        self::assertSame('charge', $exception->operation());
    }

    public function testProviderUnavailableIsRetryable(): void
    {
        $exception = new ProviderUnavailable('503', httpStatus: 503);

        self::assertInstanceOf(RetryableException::class, $exception);
        self::assertTrue($exception->retryable());
        self::assertSame(503, $exception->httpStatus());
    }

    public function testRateLimitedExceptionIsRetryableAndCarriesRetryAfter(): void
    {
        $exception = new RateLimitedException('429', retryAfterSeconds: 30, httpStatus: 429);

        self::assertInstanceOf(RetryableException::class, $exception);
        self::assertTrue($exception->retryable());
        self::assertSame(30, $exception->retryAfterSeconds());
    }

    public function testRateLimitedExceptionRetryAfterDefaultsToNull(): void
    {
        self::assertNull((new RateLimitedException('429'))->retryAfterSeconds());
    }

    public function testCardDeclinedIsNotRetryable(): void
    {
        self::assertFalse((new CardDeclined('declined'))->retryable());
    }
}
