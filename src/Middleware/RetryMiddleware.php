<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

use PayBridge\Exception\RateLimitedException;
use PayBridge\Resilience\Backoff\ConstantBackoff;
use PayBridge\Resilience\Backoff\ExponentialJitterBackoff;
use PayBridge\Resilience\RetryPolicy;
use PayBridge\Resilience\Sleeper;

final class RetryMiddleware implements Middleware
{
    public function __construct(
        private readonly Sleeper $sleeper,
        private readonly ExponentialJitterBackoff $backoff,
        private readonly ConstantBackoff $constantBackoff,
        private readonly int $maxAttempts,
        private readonly int $baseMs,
        private readonly int $maxMs,
    ) {}

    public function process(OperationContext $context, callable $next): mixed
    {
        $attempt = 1;

        while (true) {
            try {
                return $next($context);
            } catch (\Throwable $e) {
                if (!RetryPolicy::shouldRetry($e, $attempt, $this->maxAttempts, $context->operation, $context->capabilities)) {
                    throw $e;
                }

                $this->sleeper->sleep($this->delayFor($e, $attempt));
                ++$attempt;
            }
        }
    }

    private function delayFor(\Throwable $e, int $attempt): int
    {
        if ($e instanceof RateLimitedException && $e->retryAfterSeconds() !== null) {
            return $this->constantBackoff->delayMs(min($e->retryAfterSeconds() * 1000, $this->maxMs));
        }

        return $this->backoff->delayMs($attempt, $this->baseMs, $this->maxMs);
    }
}
