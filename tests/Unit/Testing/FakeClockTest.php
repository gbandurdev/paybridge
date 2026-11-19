<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Testing;

use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class FakeClockTest extends TestCase
{
    public function testNeverMovesOnItsOwn(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertEquals($clock->now(), $clock->now());
    }

    public function testAdvanceMovesForwardBySeconds(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $clock->advance(30);

        self::assertSame('2026-01-01T00:00:30+00:00', $clock->now()->format('c'));
    }

    public function testAdvanceAccumulatesAcrossCalls(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $clock->advance(10);
        $clock->advance(20);

        self::assertSame('2026-01-01T00:00:30+00:00', $clock->now()->format('c'));
    }

    public function testAdvanceMillisecondsMovesSubSecond(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'));

        $clock->advanceMilliseconds(250);

        self::assertSame('250000', $clock->now()->format('u'));
    }

    public function testDefaultsToAFixedInstantWhenNoneGiven(): void
    {
        $clock = new FakeClock();

        self::assertEquals($clock->now(), $clock->now());
    }
}
