<?php

declare(strict_types=1);

namespace PayBridge\Tests\Contract;

use PayBridge\Middleware\CircuitBreakerMiddleware;
use PayBridge\Middleware\IdempotencyMiddleware;
use PayBridge\Middleware\LoggingMiddleware;
use PayBridge\Middleware\Middleware;
use PayBridge\Middleware\MetricsMiddleware;
use PayBridge\Middleware\RetryMiddleware;
use PayBridge\Middleware\TimeoutMiddleware;
use PayBridge\Resilience\Backoff\ConstantBackoff;
use PayBridge\Resilience\Backoff\ExponentialJitterBackoff;
use PayBridge\Resilience\CircuitBreaker;
use PayBridge\Store\InMemory\InMemoryCache;
use PayBridge\Store\InMemory\InMemoryIdempotencyStore;
use PayBridge\Store\InMemory\InMemoryLockStore;
use PayBridge\Testing\FakeClock;
use PayBridge\Testing\FakeRandomSource;
use PayBridge\Testing\FakeSleeper;

/**
 * Builds the standard §3 middleware order (Logging -> Metrics -> Idempotency ->
 * CircuitBreaker -> Retry -> Timeout) around a FakeClock/FakeSleeper and the Phase 2
 * in-memory stores, for wrapping a driver in PipelineGateway under test. Shared by every
 * test that needs "a driver, through the full resilience pipeline" instead of a bare driver.
 */
trait PipelineTestMiddlewares
{
    /**
     * @return list<Middleware>
     */
    private function buildMiddlewares(FakeClock $clock, FakeSleeper $sleeper): array
    {
        $idempotencyStore = new InMemoryIdempotencyStore($clock);
        $lockStore = new InMemoryLockStore($clock);
        $cache = new InMemoryCache($clock);
        $breaker = new CircuitBreaker($cache, $clock, failureThreshold: 5, cooldownSeconds: 30);
        $random = FakeRandomSource::alwaysMin();

        return [
            new LoggingMiddleware(),
            new MetricsMiddleware(),
            new IdempotencyMiddleware($idempotencyStore, $lockStore, $sleeper, lockTtlSeconds: 5, recordTtlSeconds: 3600, lockPollIntervalMs: 10),
            new CircuitBreakerMiddleware($breaker),
            new RetryMiddleware($sleeper, new ExponentialJitterBackoff($random), new ConstantBackoff(), maxAttempts: 3, baseMs: 10, maxMs: 100),
            new TimeoutMiddleware($clock, timeoutMs: 5000),
        ];
    }
}
