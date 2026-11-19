<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Resilience;

use PayBridge\Resilience\RealSleeper;
use PHPUnit\Framework\TestCase;

final class RealSleeperTest extends TestCase
{
    public function testSleepsForAtLeastTheRequestedDuration(): void
    {
        $sleeper = new RealSleeper();

        $start = microtime(true);
        $sleeper->sleep(20);
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertGreaterThanOrEqual(15.0, $elapsedMs);
    }

    public function testZeroOrNegativeDoesNotSleep(): void
    {
        $sleeper = new RealSleeper();

        $start = microtime(true);
        $sleeper->sleep(0);
        $sleeper->sleep(-10);
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertLessThan(10.0, $elapsedMs);
    }
}
