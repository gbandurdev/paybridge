<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Middleware;

use PayBridge\Domain\Capability;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Middleware\OperationContext;
use PHPUnit\Framework\TestCase;

final class OperationContextTest extends TestCase
{
    public function testIdempotencyKeyComesFromTheRequestWhenPresent(): void
    {
        $context = new OperationContext(
            gateway: 'stripe',
            operation: 'charge',
            request: new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'order-1'),
            capabilities: [],
        );

        self::assertSame('order-1', $context->idempotencyKey());
    }

    public function testIdempotencyKeyIsNullWhenThereIsNoRequest(): void
    {
        $context = new OperationContext(gateway: 'stripe', operation: 'find', request: null, capabilities: []);

        self::assertNull($context->idempotencyKey());
    }

    public function testHasCapabilityReflectsTheConfiguredList(): void
    {
        $context = new OperationContext(
            gateway: 'stripe',
            operation: 'charge',
            request: null,
            capabilities: [Capability::PROVIDER_IDEMPOTENCY],
        );

        self::assertTrue($context->hasCapability(Capability::PROVIDER_IDEMPOTENCY));
        self::assertFalse($context->hasCapability(Capability::THREE_DS));
    }

    public function testWithTimeoutMsReturnsANewInstanceLeavingTheOriginalUnchanged(): void
    {
        $original = new OperationContext(gateway: 'stripe', operation: 'find', request: null, capabilities: []);

        $withTimeout = $original->withTimeoutMs(5000);

        self::assertNull($original->timeoutMs);
        self::assertSame(5000, $withTimeout->timeoutMs);
        self::assertSame($original->gateway, $withTimeout->gateway);
        self::assertSame($original->operation, $withTimeout->operation);
    }
}
