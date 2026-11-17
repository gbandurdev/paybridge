<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\Money;
use PayBridge\Exception\InvalidRequest;
use PHPUnit\Framework\TestCase;

final class CaptureRequestTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $amount = Money::of('10.00', 'EUR');

        $request = new CaptureRequest(
            paymentReference: 'pay_1',
            idempotencyKey: 'order-1234-capture',
            amount: $amount,
        );

        self::assertSame('pay_1', $request->paymentReference);
        self::assertSame('order-1234-capture', $request->idempotencyKey);
        self::assertSame($amount, $request->amount);
    }

    public function testNullAmountMeansFullCapture(): void
    {
        $request = new CaptureRequest(paymentReference: 'pay_1', idempotencyKey: 'key-1');

        self::assertNull($request->amount);
    }

    public function testEmptyIdempotencyKeyThrows(): void
    {
        $this->expectException(InvalidRequest::class);

        new CaptureRequest(paymentReference: 'pay_1', idempotencyKey: '');
    }
}
