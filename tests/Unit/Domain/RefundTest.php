<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Domain\Refund;
use PHPUnit\Framework\TestCase;

final class RefundTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $amount = Money::of('10.00', 'EUR');

        $refund = new Refund(
            providerReference: 're_1',
            paymentReference: 'pay_1',
            status: PaymentStatus::REFUNDED,
            amount: $amount,
            raw: ['id' => 're_1'],
        );

        self::assertSame('re_1', $refund->providerReference);
        self::assertSame('pay_1', $refund->paymentReference);
        self::assertSame(PaymentStatus::REFUNDED, $refund->status);
        self::assertSame($amount, $refund->amount);
        self::assertSame(['id' => 're_1'], $refund->raw);
    }

    public function testRawDefaultsToEmptyArray(): void
    {
        $refund = new Refund(
            providerReference: 're_1',
            paymentReference: 'pay_1',
            status: PaymentStatus::REFUNDED,
            amount: Money::of('10.00', 'EUR'),
        );

        self::assertSame([], $refund->raw);
    }
}
