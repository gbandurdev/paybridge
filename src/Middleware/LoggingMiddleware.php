<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

/**
 * Structurally present in the pipeline (PLAN.md §3/§12 "all middlewares"), but a pass-through
 * for Phase 2: real PSR-3 structured logging needs Http/Redactor (not built until a later
 * phase) and a logger actually injected by PaymentManager::fromConfig() (Phase 4).
 */
final class LoggingMiddleware implements Middleware
{
    public function process(OperationContext $context, callable $next): mixed
    {
        return $next($context);
    }
}
