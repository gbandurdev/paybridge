<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Middleware;

use PayBridge\Domain\Capability;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\NetworkException;
use PayBridge\Exception\RateLimitedException;
use PayBridge\Middleware\OperationContext;
use PayBridge\Middleware\RetryMiddleware;
use PayBridge\Resilience\Backoff\ConstantBackoff;
use PayBridge\Resilience\Backoff\ExponentialJitterBackoff;
use PayBridge\Testing\FakeRandomSource;
use PayBridge\Testing\FakeSleeper;
use PHPUnit\Framework\TestCase;

final class RetryMiddlewareTest extends TestCase
{
    public function testSucceedsAfterKRetryableFailures(): void
    {
        $sleeper = new FakeSleeper();
        $middleware = $this->middleware($sleeper, maxAttempts: 5);
        $calls = 0;

        $result = $middleware->process($this->context('find'), function () use (&$calls) {
            ++$calls;
            if ($calls < 3) {
                throw new NetworkException('x');
            }

            return 'result';
        });

        self::assertSame('result', $result);
        self::assertSame(3, $calls);
        self::assertSame(2, $sleeper->callCount());
        foreach ($sleeper->calls() as $delay) {
            self::assertGreaterThanOrEqual(0, $delay);
            self::assertLessThanOrEqual(5000, $delay);
        }
    }

    public function testExhaustsAllAttemptsAndRethrowsTheLastFailure(): void
    {
        $sleeper = new FakeSleeper();
        $middleware = $this->middleware($sleeper, maxAttempts: 4);
        $calls = 0;

        try {
            $middleware->process($this->context('find'), function () use (&$calls) {
                ++$calls;

                throw new NetworkException(sprintf('attempt-%d', $calls));
            });
            self::fail('Expected exception was not thrown.');
        } catch (NetworkException $e) {
            self::assertSame('attempt-4', $e->getMessage());
        }

        self::assertSame(4, $calls);
        self::assertSame(3, $sleeper->callCount());
    }

    public function testNonRetryableFailureIsRethrownImmediatelyWithoutSleeping(): void
    {
        $sleeper = new FakeSleeper();
        $middleware = $this->middleware($sleeper, maxAttempts: 5);
        $calls = 0;

        try {
            $middleware->process($this->context('find'), function () use (&$calls) {
                ++$calls;

                throw new CardDeclined('declined');
            });
            self::fail('Expected exception was not thrown.');
        } catch (CardDeclined) {
        }

        self::assertSame(1, $calls);
        self::assertSame(0, $sleeper->callCount());
    }

    public function testRateLimitedUsesRetryAfterInsteadOfExponentialFormula(): void
    {
        $sleeper = new FakeSleeper();
        $middleware = $this->middleware($sleeper, maxAttempts: 3, maxMs: 10_000);
        $calls = 0;

        $middleware->process($this->context('find'), function () use (&$calls) {
            ++$calls;
            if ($calls === 1) {
                throw new RateLimitedException('429', retryAfterSeconds: 2);
            }

            return 'ok';
        });

        self::assertSame([2000], $sleeper->calls());
    }

    public function testRateLimitedRetryAfterIsCappedAtMaxMs(): void
    {
        $sleeper = new FakeSleeper();
        $middleware = $this->middleware($sleeper, maxAttempts: 3, maxMs: 5000);
        $calls = 0;

        $middleware->process($this->context('find'), function () use (&$calls) {
            ++$calls;
            if ($calls === 1) {
                throw new RateLimitedException('429', retryAfterSeconds: 30);
            }

            return 'ok';
        });

        self::assertSame([5000], $sleeper->calls());
    }

    public function testMutatingAmbiguousNetworkExceptionWithoutProviderIdempotencyFailsFastAfterOneAttempt(): void
    {
        $sleeper = new FakeSleeper();
        $middleware = $this->middleware($sleeper, maxAttempts: 5);
        $calls = 0;

        try {
            $middleware->process($this->context('charge', []), function () use (&$calls) {
                ++$calls;

                throw new NetworkException('timed out', ambiguous: true);
            });
            self::fail('Expected exception was not thrown.');
        } catch (NetworkException) {
        }

        self::assertSame(1, $calls);
        self::assertSame(0, $sleeper->callCount());
    }

    public function testMutatingRateLimitedWithoutProviderIdempotencyStillRetriesNormally(): void
    {
        $sleeper = new FakeSleeper();
        $middleware = $this->middleware($sleeper, maxAttempts: 5);
        $calls = 0;

        $result = $middleware->process($this->context('charge', []), function () use (&$calls) {
            ++$calls;
            if ($calls === 1) {
                throw new RateLimitedException('429', retryAfterSeconds: 1);
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $calls);
        self::assertSame(1, $sleeper->callCount());
    }

    private function middleware(FakeSleeper $sleeper, int $maxAttempts, int $baseMs = 200, int $maxMs = 5000): RetryMiddleware
    {
        return new RetryMiddleware(
            $sleeper,
            new ExponentialJitterBackoff(FakeRandomSource::alwaysMax()),
            new ConstantBackoff(),
            $maxAttempts,
            $baseMs,
            $maxMs,
        );
    }

    /**
     * @param list<Capability> $capabilities
     */
    private function context(string $operation, array $capabilities = []): OperationContext
    {
        return new OperationContext(
            gateway: 'stripe',
            operation: $operation,
            request: new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key'),
            capabilities: $capabilities,
        );
    }
}
