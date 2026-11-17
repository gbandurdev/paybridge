<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    public function testCasesAndValues(): void
    {
        self::assertSame('pending', PaymentStatus::PENDING->value);
        self::assertSame('requires_action', PaymentStatus::REQUIRES_ACTION->value);
        self::assertSame('authorized', PaymentStatus::AUTHORIZED->value);
        self::assertSame('captured', PaymentStatus::CAPTURED->value);
        self::assertSame('partially_refunded', PaymentStatus::PARTIALLY_REFUNDED->value);
        self::assertSame('refunded', PaymentStatus::REFUNDED->value);
        self::assertSame('voided', PaymentStatus::VOIDED->value);
        self::assertSame('failed', PaymentStatus::FAILED->value);
        self::assertSame('expired', PaymentStatus::EXPIRED->value);

        self::assertCount(9, PaymentStatus::cases());
    }
}
