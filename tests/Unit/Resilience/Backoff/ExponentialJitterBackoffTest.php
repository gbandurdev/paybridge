<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Resilience\Backoff;

use PayBridge\Resilience\Backoff\ExponentialJitterBackoff;
use PayBridge\Testing\FakeRandomSource;
use PHPUnit\Framework\TestCase;

final class ExponentialJitterBackoffTest extends TestCase
{
    public function testForcedToMaxEqualsTheExponentialUpperBound(): void
    {
        $backoff = new ExponentialJitterBackoff(FakeRandomSource::alwaysMax());

        self::assertSame(400, $backoff->delayMs(attempt: 1, baseMs: 200, maxMs: 5000));
        self::assertSame(800, $backoff->delayMs(attempt: 2, baseMs: 200, maxMs: 5000));
        self::assertSame(1600, $backoff->delayMs(attempt: 3, baseMs: 200, maxMs: 5000));
    }

    public function testForcedToMinIsAlwaysZero(): void
    {
        $backoff = new ExponentialJitterBackoff(FakeRandomSource::alwaysMin());

        self::assertSame(0, $backoff->delayMs(attempt: 1, baseMs: 200, maxMs: 5000));
        self::assertSame(0, $backoff->delayMs(attempt: 5, baseMs: 200, maxMs: 5000));
    }

    public function testUpperBoundSaturatesAtMaxMsOnceExceeded(): void
    {
        $backoff = new ExponentialJitterBackoff(FakeRandomSource::alwaysMax());

        // 200 * 2^10 = 204800, far past maxMs — must cap exactly at maxMs, not overflow past it.
        self::assertSame(5000, $backoff->delayMs(attempt: 10, baseMs: 200, maxMs: 5000));
    }
}
