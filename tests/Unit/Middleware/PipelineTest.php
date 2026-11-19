<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Middleware;

use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Middleware\Middleware;
use PayBridge\Middleware\OperationContext;
use PayBridge\Middleware\Pipeline;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testMiddlewaresRunInConfiguredOrderAroundTheTerminal(): void
    {
        $log = new TraceLog();
        $makeTracer = static function (string $name) use ($log): Middleware {
            return new class ($name, $log) implements Middleware {
                public function __construct(private readonly string $name, private readonly TraceLog $log) {}

                public function process(OperationContext $context, callable $next): mixed
                {
                    $this->log->append("{$this->name}-before");
                    $result = $next($context);
                    $this->log->append("{$this->name}-after");

                    return $result;
                }
            };
        };

        $pipeline = new Pipeline(
            [$makeTracer('A'), $makeTracer('B')],
            function (OperationContext $context) use ($log): string {
                $log->append('terminal');

                return 'result';
            },
        );

        $result = $pipeline->run($this->context());

        self::assertSame('result', $result);
        self::assertSame(['A-before', 'B-before', 'terminal', 'B-after', 'A-after'], $log->all());
    }

    public function testMiddlewareThatDoesNotCallNextShortCircuitsEverythingInside(): void
    {
        $innerCalled = false;
        $terminalCalled = false;

        $shortCircuit = new class implements Middleware {
            public function process(OperationContext $context, callable $next): mixed
            {
                return 'short-circuited';
            }
        };

        $inner = new class (function () use (&$innerCalled): void {
            $innerCalled = true;
        }) implements Middleware {
            public function __construct(private readonly \Closure $onCall) {}

            public function process(OperationContext $context, callable $next): mixed
            {
                ($this->onCall)();

                return $next($context);
            }
        };

        $pipeline = new Pipeline([$shortCircuit, $inner], function () use (&$terminalCalled) {
            $terminalCalled = true;

            return 'terminal';
        });

        $result = $pipeline->run($this->context());

        self::assertSame('short-circuited', $result);
        self::assertFalse($innerCalled);
        self::assertFalse($terminalCalled);
    }

    public function testEmptyMiddlewareListInvokesTheTerminalDirectly(): void
    {
        $pipeline = new Pipeline([], fn(OperationContext $context) => $context->gateway);

        self::assertSame('stripe', $pipeline->run($this->context()));
    }

    public function testExceptionFromTerminalPropagatesThroughUncatchingMiddlewares(): void
    {
        $passthrough = new class implements Middleware {
            public function process(OperationContext $context, callable $next): mixed
            {
                return $next($context);
            }
        };

        $pipeline = new Pipeline([$passthrough, $passthrough], function () {
            throw new \RuntimeException('boom');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $pipeline->run($this->context());
    }

    public function testContextMutationsViaWithMethodsAreVisibleToInnerStagesAndTerminal(): void
    {
        $attachTimeout = new class implements Middleware {
            public function process(OperationContext $context, callable $next): mixed
            {
                return $next($context->withTimeoutMs(5000));
            }
        };

        $pipeline = new Pipeline([$attachTimeout], fn(OperationContext $context) => $context->timeoutMs);

        self::assertSame(5000, $pipeline->run($this->context()));
    }

    private function context(): OperationContext
    {
        return new OperationContext(
            gateway: 'stripe',
            operation: 'charge',
            request: new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key'),
            capabilities: [],
        );
    }
}

final class TraceLog
{
    /** @var list<string> */
    private array $entries = [];

    public function append(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
