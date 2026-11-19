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

    public function testToArrayFromArrayRoundTripWithAllFieldsPresent(): void
    {
        $payment = new Payment(
            providerReference: 'pay_1',
            status: PaymentStatus::PARTIALLY_REFUNDED,
            amount: Money::of('100.00', 'EUR'),
            capturedAmount: Money::of('100.00', 'EUR'),
            refundedAmount: Money::of('30.00', 'EUR'),
            nextAction: new NextAction(NextActionType::REDIRECT, url: 'https://pay.example', fields: ['foo' => 'bar']),
            raw: ['id' => 'pay_1'],
        );

        $reconstructed = Payment::fromArray($payment->toArray());

        $originalCaptured = $payment->capturedAmount;
        $originalRefunded = $payment->refundedAmount;
        $originalNextAction = $payment->nextAction;
        self::assertNotNull($originalCaptured);
        self::assertNotNull($originalRefunded);
        self::assertNotNull($originalNextAction);

        self::assertSame($payment->providerReference, $reconstructed->providerReference);
        self::assertSame($payment->status, $reconstructed->status);
        self::assertTrue($payment->amount->equals($reconstructed->amount));
        self::assertNotNull($reconstructed->capturedAmount);
        self::assertTrue($originalCaptured->equals($reconstructed->capturedAmount));
        self::assertNotNull($reconstructed->refundedAmount);
        self::assertTrue($originalRefunded->equals($reconstructed->refundedAmount));
        self::assertNotNull($reconstructed->nextAction);
        self::assertSame($originalNextAction->type, $reconstructed->nextAction->type);
        self::assertSame($originalNextAction->url, $reconstructed->nextAction->url);
        self::assertSame($originalNextAction->fields, $reconstructed->nextAction->fields);
        self::assertSame($payment->raw, $reconstructed->raw);
    }

    public function testToArrayFromArrayRoundTripWithNullableFieldsAbsent(): void
    {
        $payment = new Payment(
            providerReference: 'pay_1',
            status: PaymentStatus::AUTHORIZED,
            amount: Money::of('100.00', 'EUR'),
        );

        $reconstructed = Payment::fromArray($payment->toArray());

        self::assertNull($reconstructed->capturedAmount);
        self::assertNull($reconstructed->refundedAmount);
        self::assertNull($reconstructed->nextAction);
    }

    public function testToArrayNeverUsesPhpSerialize(): void
    {
        $payment = new Payment(
            providerReference: 'pay_1',
            status: PaymentStatus::CAPTURED,
            amount: Money::of('100.00', 'EUR'),
        );

        $encoded = json_encode($payment->toArray());

        self::assertIsString($encoded);
        self::assertStringNotContainsString('O:', $encoded);
    }
}
