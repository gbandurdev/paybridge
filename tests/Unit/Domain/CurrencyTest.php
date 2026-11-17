<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function testDefaultExponentIsTwo(): void
    {
        self::assertSame(2, Currency::of('EUR')->exponent);
        self::assertSame(2, Currency::of('USD')->exponent);
    }

    public function testZeroDecimalCurrency(): void
    {
        self::assertSame(0, Currency::of('JPY')->exponent);
    }

    public function testThreeDecimalCurrency(): void
    {
        self::assertSame(3, Currency::of('KWD')->exponent);
    }

    public function testCodeIsUppercased(): void
    {
        self::assertSame('EUR', Currency::of('eur')->code);
    }

    public function testInvalidCodeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Currency::of('EURO');
    }

    public function testEqualsComparesByCode(): void
    {
        self::assertTrue(Currency::of('EUR')->equals(Currency::of('EUR')));
        self::assertFalse(Currency::of('EUR')->equals(Currency::of('USD')));
    }
}
