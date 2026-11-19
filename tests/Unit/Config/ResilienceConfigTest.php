<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Config;

use PayBridge\Config\ResilienceConfig;
use PHPUnit\Framework\TestCase;

final class ResilienceConfigTest extends TestCase
{
    public function testEmptyArrayUsesAllDefaults(): void
    {
        $config = ResilienceConfig::fromArray([]);

        self::assertSame(10_000, $config->timeoutMs);
        self::assertSame(4, $config->retryMaxAttempts);
        self::assertSame(200, $config->retryBaseMs);
        self::assertSame(5_000, $config->retryMaxMs);
        self::assertSame(5, $config->circuitFailureThreshold);
        self::assertSame(30, $config->circuitCooldownSeconds);
        self::assertSame(30, $config->idempotencyLockTtlSeconds);
        self::assertSame(86_400, $config->idempotencyRecordTtlSeconds);
    }

    public function testParsesEveryFieldFromThePlanMdShape(): void
    {
        $config = ResilienceConfig::fromArray([
            'timeout_ms' => 5_000,
            'retry' => ['max_attempts' => 6, 'base_ms' => 100, 'max_ms' => 2_000, 'jitter' => 'full'],
            'circuit_breaker' => ['failure_threshold' => 3, 'cooldown_s' => 15],
        ]);

        self::assertSame(5_000, $config->timeoutMs);
        self::assertSame(6, $config->retryMaxAttempts);
        self::assertSame(100, $config->retryBaseMs);
        self::assertSame(2_000, $config->retryMaxMs);
        self::assertSame(3, $config->circuitFailureThreshold);
        self::assertSame(15, $config->circuitCooldownSeconds);
    }

    public function testUnsupportedJitterStrategyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ResilienceConfig::fromArray(['retry' => ['jitter' => 'decorrelated']]);
    }
}
