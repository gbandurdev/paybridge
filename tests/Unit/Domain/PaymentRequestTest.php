<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\Customer;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Exception\InvalidRequest;
use PHPUnit\Framework\TestCase;

final class PaymentRequestTest extends TestCase
{
    public function testValidConstruction(): void
    {
        $amount = Money::of('49.99', 'EUR');
        $customer = new Customer(id: 'cus_1');

        $request = new PaymentRequest(
            amount: $amount,
            idempotencyKey: 'order-1234-charge',
            orderId: 'order-1234',
            description: 'Order #1234',
            returnUrl: 'https://shop.test/return',
            metadata: ['customer_id' => '42'],
            customer: $customer,
        );

        self::assertSame($amount, $request->amount);
        self::assertSame('order-1234-charge', $request->idempotencyKey);
        self::assertSame('order-1234', $request->orderId);
        self::assertSame('Order #1234', $request->description);
        self::assertSame('https://shop.test/return', $request->returnUrl);
        self::assertSame(['customer_id' => '42'], $request->metadata);
        self::assertSame($customer, $request->customer);
    }

    public function testDefaults(): void
    {
        $request = new PaymentRequest(
            amount: Money::of('10.00', 'EUR'),
            idempotencyKey: 'key-1',
        );

        self::assertNull($request->orderId);
        self::assertNull($request->description);
        self::assertNull($request->returnUrl);
        self::assertSame([], $request->metadata);
        self::assertNull($request->customer);
    }

    public function testEmptyIdempotencyKeyThrows(): void
    {
        $this->expectException(InvalidRequest::class);

        new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: '');
    }

    public function testBlankIdempotencyKeyThrows(): void
    {
        $this->expectException(InvalidRequest::class);

        new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: '   ');
    }
}
