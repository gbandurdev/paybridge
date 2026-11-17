<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Exception;

use PayBridge\Exception\PaymentException;
use PHPUnit\Framework\TestCase;

final class PaymentExceptionTest extends TestCase
{
    public function testGettersReturnConstructorArguments(): void
    {
        $previous = new \RuntimeException('cause');

        $exception = new PaymentException(
            message: 'boom',
            gateway: 'stripe',
            operation: 'charge',
            providerCode: 'card_declined',
            httpStatus: 402,
            retryable: true,
            raw: ['foo' => 'bar'],
            previous: $previous,
        );

        self::assertSame('boom', $exception->getMessage());
        self::assertSame('stripe', $exception->gateway());
        self::assertSame('charge', $exception->operation());
        self::assertSame('card_declined', $exception->providerCode());
        self::assertSame(402, $exception->httpStatus());
        self::assertTrue($exception->retryable());
        self::assertSame(['foo' => 'bar'], $exception->raw());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testDefaults(): void
    {
        $exception = new PaymentException('boom');

        self::assertNull($exception->gateway());
        self::assertNull($exception->operation());
        self::assertNull($exception->providerCode());
        self::assertNull($exception->httpStatus());
        self::assertFalse($exception->retryable());
        self::assertSame([], $exception->raw());
    }
}
