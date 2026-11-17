<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\Currency;
use PayBridge\Domain\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testOfParsesDecimalIntoMinorUnits(): void
    {
        $money = Money::of('49.99', 'EUR');

        self::assertSame(4999, $money->minorUnits);
        self::assertSame('49.99', $money->toDecimalString());
    }

    public function testOfMinorRoundTrips(): void
    {
        self::assertSame('49.99', Money::ofMinor(4999, 'EUR')->toDecimalString());
    }

    public function testZeroDecimalCurrencyDoesNotMultiplyBy100(): void
    {
        self::assertSame(100, Money::of('100', 'JPY')->minorUnits);
        self::assertSame('100', Money::of('100', 'JPY')->toDecimalString());
    }

    public function testThreeDecimalCurrency(): void
    {
        self::assertSame(1234, Money::of('1.234', 'KWD')->minorUnits);
        self::assertSame('1.234', Money::of('1.234', 'KWD')->toDecimalString());
    }

    public function testTooManyFractionalDigitsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('49.999', 'EUR');
    }

    public function testNegativeDecimalStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('-1', 'EUR');
    }

    public function testNegativeMinorUnitsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::ofMinor(-1, 'EUR');
    }

    public function testAcceptsCurrencyInstanceDirectly(): void
    {
        $money = Money::of('10.00', Currency::of('EUR'));

        self::assertTrue($money->currency->equals(Currency::of('EUR')));
    }

    public function testAddSameCurrency(): void
    {
        $sum = Money::of('10.00', 'EUR')->add(Money::of('5.50', 'EUR'));

        self::assertSame('15.50', $sum->toDecimalString());
    }

    public function testAddMismatchedCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('10.00', 'EUR')->add(Money::of('5.00', 'USD'));
    }

    public function testSubtractSameCurrency(): void
    {
        $diff = Money::of('10.00', 'EUR')->subtract(Money::of('4.00', 'EUR'));

        self::assertSame('6.00', $diff->toDecimalString());
    }

    public function testSubtractMismatchedCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('10.00', 'EUR')->subtract(Money::of('5.00', 'USD'));
    }

    public function testSubtractBelowZeroThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('1.00', 'EUR')->subtract(Money::of('2.00', 'EUR'));
    }

    public function testEquals(): void
    {
        self::assertTrue(Money::of('10.00', 'EUR')->equals(Money::of('10.00', 'EUR')));
        self::assertFalse(Money::of('10.00', 'EUR')->equals(Money::of('10.01', 'EUR')));
        self::assertFalse(Money::of('10.00', 'EUR')->equals(Money::of('10.00', 'USD')));
    }

    public function testGreaterThanAndLessThan(): void
    {
        self::assertTrue(Money::of('10.01', 'EUR')->greaterThan(Money::of('10.00', 'EUR')));
        self::assertTrue(Money::of('10.00', 'EUR')->lessThan(Money::of('10.01', 'EUR')));
        self::assertFalse(Money::of('10.00', 'EUR')->greaterThan(Money::of('10.00', 'EUR')));
    }

    public function testGreaterThanMismatchedCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('10.00', 'EUR')->greaterThan(Money::of('10.00', 'USD'));
    }

    public function testLessThanMismatchedCurrencyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('10.00', 'EUR')->lessThan(Money::of('10.00', 'USD'));
    }

    public function testIsZero(): void
    {
        self::assertTrue(Money::ofMinor(0, 'EUR')->isZero());
        self::assertFalse(Money::of('0.01', 'EUR')->isZero());
    }

    public function testInvalidDecimalStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of('abc', 'EUR');
    }
}
