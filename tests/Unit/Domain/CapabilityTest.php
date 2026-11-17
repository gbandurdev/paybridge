<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\Capability;
use PHPUnit\Framework\TestCase;

final class CapabilityTest extends TestCase
{
    public function testCasesAndValues(): void
    {
        self::assertSame('authorize', Capability::AUTHORIZE->value);
        self::assertSame('capture', Capability::CAPTURE->value);
        self::assertSame('refund', Capability::REFUND->value);
        self::assertSame('refund_partial', Capability::REFUND_PARTIAL->value);
        self::assertSame('void', Capability::VOID->value);
        self::assertSame('redirect_flow', Capability::REDIRECT_FLOW->value);
        self::assertSame('webhooks', Capability::WEBHOOKS->value);
        self::assertSame('three_ds', Capability::THREE_DS->value);
        self::assertSame('provider_idempotency', Capability::PROVIDER_IDEMPOTENCY->value);

        self::assertCount(9, Capability::cases());
    }
}
