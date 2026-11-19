<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

/**
 * Composes an ordered list of middlewares (outer -> inner) around a terminal callable
 * (the actual driver call), onion-style. The first middleware in the list is outermost.
 */
final class Pipeline
{
    private readonly \Closure $terminal;

    /**
     * @param list<Middleware> $middlewares
     * @param callable(OperationContext): mixed $terminal
     */
    public function __construct(
        private readonly array $middlewares,
        callable $terminal,
    ) {
        $this->terminal = \Closure::fromCallable($terminal);
    }

    public function run(OperationContext $context): mixed
    {
        $next = $this->terminal;

        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = static fn(OperationContext $ctx): mixed => $middleware->process($ctx, $next);
        }

        return $next($context);
    }
}
