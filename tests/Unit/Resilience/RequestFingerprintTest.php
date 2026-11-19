<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Resilience;

use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\Customer;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\RefundRequest;
use PayBridge\Resilience\RequestFingerprint;
use PHPUnit\Framework\TestCase;

final class RequestFingerprintTest extends TestCase
{
    public function testSameRequestDifferentIdempotencyKeyProducesSameFingerprint(): void
    {
        $base = ['amount' => Money::of('10.00', 'EUR'), 'orderId' => 'order-1'];

        $a = new PaymentRequest(...$base, idempotencyKey: 'key-a');
        $b = new PaymentRequest(...$base, idempotencyKey: 'key-b');

        self::assertSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
    }

    public function testDifferentAmountProducesDifferentFingerprint(): void
    {
        $a = new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key');
        $b = new PaymentRequest(amount: Money::of('10.01', 'EUR'), idempotencyKey: 'key');

        self::assertNotSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
    }

    public function testDifferentOrderIdProducesDifferentFingerprint(): void
    {
        $a = new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key', orderId: 'order-1');
        $b = new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key', orderId: 'order-2');

        self::assertNotSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
    }

    public function testDifferentMetadataProducesDifferentFingerprint(): void
    {
        $a = new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key', metadata: ['a' => 1]);
        $b = new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key', metadata: ['a' => 2]);

        self::assertNotSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
    }

    public function testDifferentCustomerProducesDifferentFingerprint(): void
    {
        $a = new PaymentRequest(
            amount: Money::of('10.00', 'EUR'),
            idempotencyKey: 'key',
            customer: new Customer(id: 'cus_1'),
        );
        $b = new PaymentRequest(
            amount: Money::of('10.00', 'EUR'),
            idempotencyKey: 'key',
            customer: new Customer(id: 'cus_2'),
        );

        self::assertNotSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
    }

    public function testMetadataKeyOrderDoesNotAffectFingerprint(): void
    {
        $a = new PaymentRequest(
            amount: Money::of('10.00', 'EUR'),
            idempotencyKey: 'key',
            metadata: ['first' => '1', 'second' => '2'],
        );
        $b = new PaymentRequest(
            amount: Money::of('10.00', 'EUR'),
            idempotencyKey: 'key',
            metadata: ['second' => '2', 'first' => '1'],
        );

        self::assertSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
    }

    public function testMoneyConstructedDifferentlyButNumericallyEqualProducesSameFingerprint(): void
    {
        $a = new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key');
        $b = new PaymentRequest(amount: Money::ofMinor(1000, 'EUR'), idempotencyKey: 'key');

        self::assertSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
    }

    public function testOutputIsADeterministic64CharacterHexString(): void
    {
        $request = new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key');

        $fingerprint = RequestFingerprint::compute($request);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        self::assertSame($fingerprint, RequestFingerprint::compute($request));
    }

    public function testWorksForRefundRequestAndStripsIdempotencyKey(): void
    {
        $a = new RefundRequest(paymentReference: 'pay_1', idempotencyKey: 'key-a', amount: Money::of('5.00', 'EUR'));
        $b = new RefundRequest(paymentReference: 'pay_1', idempotencyKey: 'key-b', amount: Money::of('5.00', 'EUR'));
        $c = new RefundRequest(paymentReference: 'pay_1', idempotencyKey: 'key-a', amount: Money::of('6.00', 'EUR'));

        self::assertSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
        self::assertNotSame(RequestFingerprint::compute($a), RequestFingerprint::compute($c));
    }

    public function testWorksForCaptureRequestAndStripsIdempotencyKey(): void
    {
        $a = new CaptureRequest(paymentReference: 'pay_1', idempotencyKey: 'key-a', amount: Money::of('5.00', 'EUR'));
        $b = new CaptureRequest(paymentReference: 'pay_1', idempotencyKey: 'key-b', amount: Money::of('5.00', 'EUR'));
        $c = new CaptureRequest(paymentReference: 'pay_2', idempotencyKey: 'key-a', amount: Money::of('5.00', 'EUR'));

        self::assertSame(RequestFingerprint::compute($a), RequestFingerprint::compute($b));
        self::assertNotSame(RequestFingerprint::compute($a), RequestFingerprint::compute($c));
    }
}
