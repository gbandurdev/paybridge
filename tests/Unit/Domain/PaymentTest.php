<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\Money;
use PayBridge\Domain\NextAction;
use PayBridge\Domain\NextActionType;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $amount = Money::of('49.99', 'EUR');
        $nextAction = new NextAction(NextActionType::REDIRECT, url: 'https://pay.example');

        $payment = new Payment(
            providerReference: 'pay_1',
            status: PaymentStatus::REQUIRES_ACTION,
            amount: $amount,
            capturedAmount: null,
            refundedAmount: null,
            nextAction: $nextAction,
            raw: ['id' => 'pay_1'],
        );

        self::assertSame('pay_1', $payment->providerReference);
        self::assertSame(PaymentStatus::REQUIRES_ACTION, $payment->status);
        self::assertSame($amount, $payment->amount);
        self::assertSame($nextAction, $payment->nextAction);
        self::assertSame(['id' => 'pay_1'], $payment->raw);
    }

    public function testRefundableAmountIsCapturedMinusRefunded(): void
    {
        $payment = new Payment(
            providerReference: 'pay_1',
            status: PaymentStatus::PARTIALLY_REFUNDED,
            amount: Money::of('100.00', 'EUR'),
            capturedAmount: Money::of('100.00', 'EUR'),
            refundedAmount: Money::of('30.00', 'EUR'),
        );

        self::assertSame('70.00', $payment->refundableAmount()->toDecimalString());
    }

    public function testRefundableAmountWhenNothingRefundedYet(): void
    {
        $payment = new Payment(
            providerReference: 'pay_1',
            status: PaymentStatus::CAPTURED,
            amount: Money::of('100.00', 'EUR'),
            capturedAmount: Money::of('100.00', 'EUR'),
        );

        self::assertSame('100.00', $payment->refundableAmount()->toDecimalString());
    }

    public function testRefundableAmountWhenNotCapturedYet(): void
    {
        $payment = new Payment(
            providerReference: 'pay_1',
            status: PaymentStatus::AUTHORIZED,
            amount: Money::of('100.00', 'EUR'),
        );

        self::assertSame('0.00', $payment->refundableAmount()->toDecimalString());
    }
}
