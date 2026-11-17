<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Status;

use PayBridge\Domain\PaymentStatus;
use PayBridge\Status\StateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    private StateMachine $stateMachine;

    protected function setUp(): void
    {
        $this->stateMachine = new StateMachine();
    }

    /**
     * @return iterable<string, array{PaymentStatus, PaymentStatus}>
     */
    public static function legalTransitions(): iterable
    {
        yield 'pending to requires_action' => [PaymentStatus::PENDING, PaymentStatus::REQUIRES_ACTION];
        yield 'pending to authorized' => [PaymentStatus::PENDING, PaymentStatus::AUTHORIZED];
        yield 'pending to captured' => [PaymentStatus::PENDING, PaymentStatus::CAPTURED];
        yield 'pending to failed' => [PaymentStatus::PENDING, PaymentStatus::FAILED];
        yield 'pending to expired' => [PaymentStatus::PENDING, PaymentStatus::EXPIRED];
        yield 'requires_action to authorized' => [PaymentStatus::REQUIRES_ACTION, PaymentStatus::AUTHORIZED];
        yield 'requires_action to captured' => [PaymentStatus::REQUIRES_ACTION, PaymentStatus::CAPTURED];
        yield 'requires_action to failed' => [PaymentStatus::REQUIRES_ACTION, PaymentStatus::FAILED];
        yield 'authorized to captured' => [PaymentStatus::AUTHORIZED, PaymentStatus::CAPTURED];
        yield 'authorized to voided' => [PaymentStatus::AUTHORIZED, PaymentStatus::VOIDED];
        yield 'authorized to failed' => [PaymentStatus::AUTHORIZED, PaymentStatus::FAILED];
        yield 'captured to partially_refunded' => [PaymentStatus::CAPTURED, PaymentStatus::PARTIALLY_REFUNDED];
        yield 'captured to refunded' => [PaymentStatus::CAPTURED, PaymentStatus::REFUNDED];
        yield 'partially_refunded to partially_refunded again' => [
            PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::PARTIALLY_REFUNDED,
        ];
        yield 'partially_refunded to refunded' => [PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::REFUNDED];
    }

    #[DataProvider('legalTransitions')]
    public function testLegalTransitionsAreAllowed(PaymentStatus $from, PaymentStatus $to): void
    {
        self::assertTrue($this->stateMachine->canTransition($from, $to));
    }

    /**
     * @return iterable<string, array{PaymentStatus, PaymentStatus}>
     */
    public static function illegalTransitions(): iterable
    {
        yield 'captured to authorized (backwards)' => [PaymentStatus::CAPTURED, PaymentStatus::AUTHORIZED];
        yield 'refunded to captured (stale, out of order)' => [PaymentStatus::REFUNDED, PaymentStatus::CAPTURED];
        yield 'voided to authorized' => [PaymentStatus::VOIDED, PaymentStatus::AUTHORIZED];
        yield 'failed to pending' => [PaymentStatus::FAILED, PaymentStatus::PENDING];
        yield 'expired to authorized' => [PaymentStatus::EXPIRED, PaymentStatus::AUTHORIZED];
        yield 'pending to partially_refunded' => [PaymentStatus::PENDING, PaymentStatus::PARTIALLY_REFUNDED];
        yield 'authorized to partially_refunded' => [PaymentStatus::AUTHORIZED, PaymentStatus::PARTIALLY_REFUNDED];
        yield 'refunded to partially_refunded' => [PaymentStatus::REFUNDED, PaymentStatus::PARTIALLY_REFUNDED];
    }

    #[DataProvider('illegalTransitions')]
    public function testIllegalTransitionsAreRejected(PaymentStatus $from, PaymentStatus $to): void
    {
        self::assertFalse($this->stateMachine->canTransition($from, $to));
    }

    public function testSameStatusTransitionIsTolerated(): void
    {
        foreach (PaymentStatus::cases() as $status) {
            self::assertTrue($this->stateMachine->canTransition($status, $status));
        }
    }

    /**
     * @return iterable<string, array{PaymentStatus, bool}>
     */
    public static function terminalStatuses(): iterable
    {
        yield 'refunded is terminal' => [PaymentStatus::REFUNDED, true];
        yield 'voided is terminal' => [PaymentStatus::VOIDED, true];
        yield 'failed is terminal' => [PaymentStatus::FAILED, true];
        yield 'expired is terminal' => [PaymentStatus::EXPIRED, true];
        yield 'pending is not terminal' => [PaymentStatus::PENDING, false];
        yield 'requires_action is not terminal' => [PaymentStatus::REQUIRES_ACTION, false];
        yield 'authorized is not terminal' => [PaymentStatus::AUTHORIZED, false];
        yield 'captured is not terminal' => [PaymentStatus::CAPTURED, false];
        yield 'partially_refunded is not terminal' => [PaymentStatus::PARTIALLY_REFUNDED, false];
    }

    #[DataProvider('terminalStatuses')]
    public function testIsTerminal(PaymentStatus $status, bool $expected): void
    {
        self::assertSame($expected, $this->stateMachine->isTerminal($status));
    }
}
