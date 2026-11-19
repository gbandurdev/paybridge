<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

interface Middleware
{
    /**
     * @param callable(OperationContext): mixed $next
     */
    public function process(OperationContext $context, callable $next): mixed;
}
