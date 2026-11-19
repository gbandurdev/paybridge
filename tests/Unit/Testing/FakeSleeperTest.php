<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Testing;

use PayBridge\Testing\FakeClock;
use PayBridge\Testing\FakeSleeper;
use PHPUnit\Framework\TestCase;

final class FakeSleeperTest extends TestCase
{
    public function testRecordsEachSleepCallWithoutActuallyWaiting(): void
    {
        $sleeper = new FakeSleeper();

        $start = microtime(true);
        $sleeper->sleep(5000);
        $sleeper->sleep(1000);
        $elapsedRealMs = (microtime(true) - $start) * 1000;

        self::assertSame([5000, 1000], $sleeper->calls());
        self::assertSame(2, $sleeper->callCount());
        self::assertSame(6000, $sleeper->totalMilliseconds());
        self::assertLessThan(100, $elapsedRealMs);
    }

    public function testAdvancesAWrappedFakeClockOnEachSleep(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $clock = new FakeClock($start);
        $sleeper = new FakeSleeper($clock);

        $sleeper->sleep(1500);

        $elapsedMs = ((float) $clock->now()->format('U.u') - (float) $start->format('U.u')) * 1000;
        self::assertEqualsWithDelta(1500.0, $elapsedMs, 0.001);
    }

    public function testWithNoClockItStillRecordsCalls(): void
    {
        $sleeper = new FakeSleeper();

        $sleeper->sleep(10);

        self::assertSame([10], $sleeper->calls());
    }
}
