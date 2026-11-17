<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\Money;
use PayBridge\Domain\RefundRequest;
use PayBridge\Exception\InvalidRequest;
use PHPUnit\Framework\TestCase;

final class RefundRequestTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $amount = Money::of('10.00', 'EUR');

        $request = new RefundRequest(
            paymentReference: 'pay_1',
            idempotencyKey: 'order-1234-refund-1',
            amount: $amount,
            reason: 'requested_by_customer',
        );

        self::assertSame('pay_1', $request->paymentReference);
        self::assertSame('order-1234-refund-1', $request->idempotencyKey);
        self::assertSame($amount, $request->amount);
        self::assertSame('requested_by_customer', $request->reason);
    }

    public function testNullAmountMeansFullRefund(): void
    {
        $request = new RefundRequest(paymentReference: 'pay_1', idempotencyKey: 'key-1');

        self::assertNull($request->amount);
        self::assertNull($request->reason);
    }

    public function testEmptyIdempotencyKeyThrows(): void
    {
        $this->expectException(InvalidRequest::class);

        new RefundRequest(paymentReference: 'pay_1', idempotencyKey: '');
    }
}
