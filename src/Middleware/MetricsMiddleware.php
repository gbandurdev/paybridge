<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

/**
 * Structurally present in the pipeline (PLAN.md §3/§12 "all middlewares"), but a pass-through
 * for Phase 2: a real MetricsCollector contract isn't introduced until it's actually needed.
 */
final class MetricsMiddleware implements Middleware
{
    public function process(OperationContext $context, callable $next): mixed
    {
        return $next($context);
    }
}
