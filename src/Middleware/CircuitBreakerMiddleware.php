<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

use PayBridge\Exception\RetryableException;
use PayBridge\Resilience\CircuitBreaker;

final class CircuitBreakerMiddleware implements Middleware
{
    public function __construct(private readonly CircuitBreaker $breaker) {}

    public function process(OperationContext $context, callable $next): mixed
    {
        // Throws CircuitOpen here, *before* the try/catch below — so a CircuitOpen this
        // middleware itself throws can never flow back into the catch block and be counted
        // as a failure against its own breaker.
        $this->breaker->guard($context->gateway);

        try {
            $result = $next($context);
        } catch (\Throwable $e) {
            if ($e instanceof RetryableException) {
                $this->breaker->recordFailure($context->gateway);
            }

            throw $e;
        }

        $this->breaker->recordSuccess($context->gateway);

        return $result;
    }
}
