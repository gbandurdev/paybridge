<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Exception;

use PayBridge\Exception\IdempotencyConflict;
use PayBridge\Exception\IdempotencyConflictReason;
use PHPUnit\Framework\TestCase;

final class IdempotencyConflictTest extends TestCase
{
    public function testPayloadMismatchReason(): void
    {
        $exception = new IdempotencyConflict(
            'payload mismatch',
            reason: IdempotencyConflictReason::PAYLOAD_MISMATCH,
            gateway: 'stripe',
            operation: 'charge',
            raw: ['key' => 'order-1'],
        );

        self::assertSame(IdempotencyConflictReason::PAYLOAD_MISMATCH, $exception->reason());
        self::assertSame('stripe', $exception->gateway());
        self::assertSame('charge', $exception->operation());
        self::assertSame(['key' => 'order-1'], $exception->raw());
    }

    public function testInProgressReason(): void
    {
        $exception = new IdempotencyConflict('in progress', reason: IdempotencyConflictReason::IN_PROGRESS);

        self::assertSame(IdempotencyConflictReason::IN_PROGRESS, $exception->reason());
    }
}
