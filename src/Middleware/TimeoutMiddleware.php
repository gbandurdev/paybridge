<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

use PayBridge\Contracts\Clock;
use PayBridge\Exception\NetworkException;
use PayBridge\Exception\PaymentException;

/**
 * No real HTTP transport exists yet, so this can't enforce a *real* connect/read timeout —
 * that becomes the Transport's job once Psr18Transport exists. For now: attach the
 * configured budget onto the context for a future driver/transport to read, and normalize
 * an unclassified failure into a retryable, ambiguous NetworkException once elapsed time
 * reaches that budget (a timeout can never tell us whether the provider received the
 * request before we gave up).
 */
final class TimeoutMiddleware implements Middleware
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $timeoutMs,
    ) {}

    public function process(OperationContext $context, callable $next): mixed
    {
        $context = $context->withTimeoutMs($this->timeoutMs);
        $start = $this->clock->now();

        try {
            return $next($context);
        } catch (PaymentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $elapsedMs = $this->elapsedMs($start, $this->clock->now());

            if ($elapsedMs >= $this->timeoutMs) {
                throw new NetworkException(
                    sprintf('Request to "%s" timed out after %dms.', $context->gateway, $elapsedMs),
                    gateway: $context->gateway,
                    operation: $context->operation,
                    ambiguous: true,
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    private function elapsedMs(\DateTimeImmutable $start, \DateTimeImmutable $now): int
    {
        $startMs = (float) $start->format('U.u') * 1000;
        $nowMs = (float) $now->format('U.u') * 1000;

        return (int) round($nowMs - $startMs);
    }
}
