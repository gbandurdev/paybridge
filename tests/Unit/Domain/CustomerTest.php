<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\Customer;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    public function testDefaults(): void
    {
        $customer = new Customer();

        self::assertNull($customer->id);
        self::assertNull($customer->email);
        self::assertNull($customer->name);
        self::assertSame([], $customer->metadata);
    }

    public function testAllFieldsSet(): void
    {
        $customer = new Customer(
            id: 'cus_1',
            email: 'a@example.com',
            name: 'Ada Lovelace',
            metadata: ['vip' => true],
        );

        self::assertSame('cus_1', $customer->id);
        self::assertSame('a@example.com', $customer->email);
        self::assertSame('Ada Lovelace', $customer->name);
        self::assertSame(['vip' => true], $customer->metadata);
    }
}
