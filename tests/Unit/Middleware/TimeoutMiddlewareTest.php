<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Middleware;

use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\NetworkException;
use PayBridge\Middleware\OperationContext;
use PayBridge\Middleware\TimeoutMiddleware;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class TimeoutMiddlewareTest extends TestCase
{
    public function testSuccessfulInnerCallPassesThroughUnchanged(): void
    {
        $middleware = new TimeoutMiddleware(new FakeClock(), timeoutMs: 1000);

        $result = $middleware->process($this->context(), fn(OperationContext $context) => 'result');

        self::assertSame('result', $result);
    }

    public function testPaymentExceptionFromInnerIsRethrownUnchangedRegardlessOfElapsedTime(): void
    {
        $clock = new FakeClock();
        $middleware = new TimeoutMiddleware($clock, timeoutMs: 100);
        $original = new CardDeclined('declined');

        try {
            $middleware->process($this->context(), function () use ($clock, $original) {
                $clock->advanceMilliseconds(10_000);

                throw $original;
            });
            self::fail('Expected exception was not thrown.');
        } catch (CardDeclined $caught) {
            self::assertSame($original, $caught);
        }
    }

    public function testUnclassifiedThrowableAfterElapsedPastTimeoutIsWrappedAsAmbiguousNetworkException(): void
    {
        $clock = new FakeClock();
        $middleware = new TimeoutMiddleware($clock, timeoutMs: 100);
        $original = new \RuntimeException('curl timed out');

        try {
            $middleware->process($this->context(), function () use ($clock, $original) {
                $clock->advanceMilliseconds(150);

                throw $original;
            });
            self::fail('Expected exception was not thrown.');
        } catch (NetworkException $caught) {
            self::assertTrue($caught->ambiguous());
            self::assertTrue($caught->retryable());
            self::assertSame($original, $caught->getPrevious());
        }
    }

    public function testUnclassifiedThrowableUnderTimeoutIsRethrownUnmodified(): void
    {
        $clock = new FakeClock();
        $middleware = new TimeoutMiddleware($clock, timeoutMs: 1000);
        $original = new \RuntimeException('some other bug');

        try {
            $middleware->process($this->context(), function () use ($clock, $original) {
                $clock->advanceMilliseconds(10);

                throw $original;
            });
            self::fail('Expected exception was not thrown.');
        } catch (\RuntimeException $caught) {
            self::assertSame($original, $caught);
        }
    }

    public function testTimeoutMsIsReadableFromTheContextPassedToNext(): void
    {
        $middleware = new TimeoutMiddleware(new FakeClock(), timeoutMs: 5000);

        $result = $middleware->process($this->context(), fn(OperationContext $context) => $context->timeoutMs);

        self::assertSame(5000, $result);
    }

    private function context(): OperationContext
    {
        return new OperationContext(
            gateway: 'stripe',
            operation: 'charge',
            request: new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key'),
            capabilities: [],
        );
    }
}
