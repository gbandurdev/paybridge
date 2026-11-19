<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Testing;

use PayBridge\Testing\FakeRandomSource;
use PHPUnit\Framework\TestCase;

final class FakeRandomSourceTest extends TestCase
{
    public function testAlwaysMaxReturnsTheUpperBound(): void
    {
        self::assertSame(100, FakeRandomSource::alwaysMax()->nextInt(0, 100));
        self::assertSame(5, FakeRandomSource::alwaysMax()->nextInt(5, 5));
    }

    public function testAlwaysMinReturnsTheLowerBound(): void
    {
        self::assertSame(0, FakeRandomSource::alwaysMin()->nextInt(0, 100));
    }

    public function testFixedReturnsTheConfiguredValueRegardlessOfRange(): void
    {
        self::assertSame(42, FakeRandomSource::fixed(42)->nextInt(0, 1000));
    }
}
