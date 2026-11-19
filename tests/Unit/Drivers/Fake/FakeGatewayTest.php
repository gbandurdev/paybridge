<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Drivers\Fake;

use PayBridge\Domain\Capability;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Drivers\Fake\FakeGateway;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\InvalidRequest;
use PayBridge\Exception\NetworkException;
use PayBridge\Exception\UnsupportedOperation;
use PayBridge\Middleware\PipelineGateway;
use PayBridge\Testing\FakeClock;
use PayBridge\Testing\FakeSleeper;
use PayBridge\Tests\Contract\PipelineTestMiddlewares;
use PHPUnit\Framework\TestCase;

final class FakeGatewayTest extends TestCase
{
    use PipelineTestMiddlewares;

    public function testWillFailThenSucceedRetriesExactlyTheScriptedNumberOfTimes(): void
    {
        $fake = new FakeGateway();
        $fake->willFail('charge', NetworkException::class, times: 2)->thenSucceed();

        $clock = new FakeClock();
        $sleeper = new FakeSleeper($clock);
        $gateway = new PipelineGateway($fake, $this->buildMiddlewares($clock, $sleeper));

        $payment = $gateway->charge(new PaymentRequest(Money::of('10.00', 'USD'), 'willfail-charge'));

        self::assertSame(PaymentStatus::CAPTURED, $payment->status);
        self::assertSame(2, $sleeper->callCount());
    }

    public function testWillFailOnOneOperationDoesNotAffectAnother(): void
    {
        $fake = new FakeGateway();
        $fake->willFail('refund', NetworkException::class, times: 1);

        $payment = $fake->charge(new PaymentRequest(Money::of('10.00', 'USD'), 'other-op-charge'));

        self::assertSame(PaymentStatus::CAPTURED, $payment->status);
    }

    public function testBurstOfScriptedNonRetryableFailuresNeverTripsTheCircuitBreaker(): void
    {
        $fake = new FakeGateway();
        $fake->willFail('charge', CardDeclined::class, times: 10);

        $clock = new FakeClock();
        $sleeper = new FakeSleeper($clock);
        $gateway = new PipelineGateway($fake, $this->buildMiddlewares($clock, $sleeper));

        for ($i = 0; $i < 10; ++$i) {
            try {
                $gateway->charge(new PaymentRequest(Money::of('10.00', 'USD'), sprintf('breaker-decline-%d', $i)));
                self::fail('Expected CardDeclined was not thrown.');
            } catch (CardDeclined) {
            }
        }

        // The breaker never tripped: a normal charge right after still reaches the driver.
        $payment = $gateway->charge(new PaymentRequest(Money::of('10.00', 'USD'), 'breaker-decline-final'));
        self::assertSame(PaymentStatus::CAPTURED, $payment->status);
    }

    public function testRejectedCapabilityCallLeavesExistingStateUntouched(): void
    {
        $fake = new FakeGateway(capabilities: [Capability::REFUND]);
        $payment = $fake->charge(new PaymentRequest(Money::of('10.00', 'USD'), 'untouched-charge'));

        try {
            $fake->void($payment->providerReference);
            self::fail('Expected UnsupportedOperation was not thrown.');
        } catch (UnsupportedOperation) {
        }

        $found = $fake->find($payment->providerReference);
        self::assertSame(PaymentStatus::CAPTURED, $found->status);
    }

    public function testVoidingAnAlreadyCapturedPaymentIsAnIllegalTransition(): void
    {
        $fake = new FakeGateway();
        $payment = $fake->charge(new PaymentRequest(Money::of('10.00', 'USD'), 'illegal-transition-charge'));

        $this->expectException(InvalidRequest::class);

        $fake->void($payment->providerReference);
    }
}
