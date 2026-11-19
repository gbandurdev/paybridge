<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Middleware;

use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Middleware\LoggingMiddleware;
use PayBridge\Middleware\OperationContext;
use PHPUnit\Framework\TestCase;

final class LoggingMiddlewareTest extends TestCase
{
    public function testCallsNextExactlyOnceAndReturnsItsResultUnchanged(): void
    {
        $calls = 0;
        $middleware = new LoggingMiddleware();

        $result = $middleware->process($this->context(), function (OperationContext $context) use (&$calls) {
            ++$calls;

            return 'result';
        });

        self::assertSame(1, $calls);
        self::assertSame('result', $result);
    }

    public function testLetsExceptionsPropagateUnchanged(): void
    {
        $middleware = new LoggingMiddleware();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $middleware->process($this->context(), function () {
            throw new \RuntimeException('boom');
        });
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
