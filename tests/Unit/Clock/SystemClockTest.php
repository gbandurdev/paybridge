<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Clock;

use PayBridge\Clock\SystemClock;
use PayBridge\Contracts\Clock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testNowIsCloseToRealTime(): void
    {
        $clock = new SystemClock();

        self::assertInstanceOf(Clock::class, $clock);
        self::assertEqualsWithDelta(time(), $clock->now()->getTimestamp(), 2);
    }

    public function testEachCallReturnsAFreshInstant(): void
    {
        $clock = new SystemClock();

        $first = $clock->now();
        $second = $clock->now();

        self::assertGreaterThanOrEqual($first->getTimestamp(), $second->getTimestamp());
    }
}
