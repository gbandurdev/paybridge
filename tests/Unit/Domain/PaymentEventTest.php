<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\PaymentEvent;
use PayBridge\Domain\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentEventTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-11-17T10:00:00Z');

        $event = new PaymentEvent(
            gateway: 'stripe',
            eventId: 'evt_1',
            type: 'payment_intent.succeeded',
            occurredAt: $occurredAt,
            paymentReference: 'pay_1',
            status: PaymentStatus::CAPTURED,
            raw: ['id' => 'evt_1'],
        );

        self::assertSame('stripe', $event->gateway);
        self::assertSame('evt_1', $event->eventId);
        self::assertSame('payment_intent.succeeded', $event->type);
        self::assertSame($occurredAt, $event->occurredAt);
        self::assertSame('pay_1', $event->paymentReference);
        self::assertSame(PaymentStatus::CAPTURED, $event->status);
        self::assertSame(['id' => 'evt_1'], $event->raw);
    }

    public function testNullablePropertiesDefaultToNull(): void
    {
        $event = new PaymentEvent(
            gateway: 'stripe',
            eventId: 'evt_1',
            type: 'ping',
            occurredAt: new \DateTimeImmutable(),
        );

        self::assertNull($event->paymentReference);
        self::assertNull($event->status);
        self::assertSame([], $event->raw);
    }
}
