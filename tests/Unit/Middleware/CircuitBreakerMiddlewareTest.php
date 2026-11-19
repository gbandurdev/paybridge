<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Middleware;

use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\CircuitOpen;
use PayBridge\Exception\NetworkException;
use PayBridge\Middleware\CircuitBreakerMiddleware;
use PayBridge\Middleware\OperationContext;
use PayBridge\Resilience\CircuitBreaker;
use PayBridge\Store\InMemory\InMemoryCache;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerMiddlewareTest extends TestCase
{
    public function testStaysClosedBelowFailureThreshold(): void
    {
        [$middleware] = $this->middleware(failureThreshold: 3, cooldownSeconds: 30);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $middleware->process($this->context(), $this->failingWith(new NetworkException('x')));
                self::fail('Expected exception was not thrown.');
            } catch (NetworkException) {
            }
        }

        // Breaker still closed: inner is still called on the next attempt.
        $innerCalled = false;
        try {
            $middleware->process($this->context(), function () use (&$innerCalled) {
                $innerCalled = true;

                throw new NetworkException('x');
            });
        } catch (NetworkException) {
        }
        self::assertTrue($innerCalled);
    }

    public function testThresholdReachingFailureTripsTheBreakerButStillSurfacesTheRealException(): void
    {
        [$middleware] = $this->middleware(failureThreshold: 2, cooldownSeconds: 30);

        $this->expectFailureNTimes($middleware, NetworkException::class, 2);

        $this->expectExceptionDuring($middleware, CircuitOpen::class, fn() => $middleware->process(
            $this->context(),
            fn() => self::fail('Inner must not be called while the breaker is open.'),
        ));
    }

    public function testOpenBeforeCooldownThrowsCircuitOpenWithoutCallingInner(): void
    {
        [$middleware] = $this->middleware(failureThreshold: 1, cooldownSeconds: 30);

        $this->expectFailureNTimes($middleware, NetworkException::class, 1);

        $innerCalled = false;
        try {
            $middleware->process($this->context(), function () use (&$innerCalled) {
                $innerCalled = true;

                return 'should not run';
            });
            self::fail('Expected CircuitOpen was not thrown.');
        } catch (CircuitOpen) {
        }

        self::assertFalse($innerCalled);
    }

    public function testOpenAfterCooldownElapsesTransitionsToHalfOpenAndAllowsOneTrial(): void
    {
        [$middleware, , $clock] = $this->middleware(failureThreshold: 1, cooldownSeconds: 30);

        $this->expectFailureNTimes($middleware, NetworkException::class, 1);

        $clock->advance(31);

        $trialCalled = false;
        $result = $middleware->process($this->context(), function () use (&$trialCalled) {
            $trialCalled = true;

            return 'trial-result';
        });

        self::assertTrue($trialCalled);
        self::assertSame('trial-result', $result);
    }

    public function testHalfOpenTrialSucceedingClosesTheBreaker(): void
    {
        [$middleware, , $clock] = $this->middleware(failureThreshold: 1, cooldownSeconds: 30);

        $this->expectFailureNTimes($middleware, NetworkException::class, 1);
        $clock->advance(31);
        $middleware->process($this->context(), fn() => 'trial-succeeds');

        // Breaker is closed again: a normal call goes through without CircuitOpen.
        $innerCalled = false;
        $result = $middleware->process($this->context(), function () use (&$innerCalled) {
            $innerCalled = true;

            return 'normal';
        });

        self::assertTrue($innerCalled);
        self::assertSame('normal', $result);
    }

    public function testHalfOpenTrialFailingReopensImmediately(): void
    {
        [$middleware, , $clock] = $this->middleware(failureThreshold: 1, cooldownSeconds: 30);

        $this->expectFailureNTimes($middleware, NetworkException::class, 1);
        $clock->advance(31);

        try {
            $middleware->process($this->context(), $this->failingWith(new NetworkException('trial fails')));
            self::fail('Expected exception was not thrown.');
        } catch (NetworkException) {
        }

        // Immediately (no new cooldown elapsed) -> CircuitOpen again.
        try {
            $middleware->process($this->context(), fn() => self::fail('Inner must not run while reopened.'));
            self::fail('Expected CircuitOpen was not thrown.');
        } catch (CircuitOpen) {
        }
    }

    public function testNonRetryableFailuresNeverTripTheBreaker(): void
    {
        [$middleware] = $this->middleware(failureThreshold: 1, cooldownSeconds: 30);

        for ($i = 0; $i < 10; ++$i) {
            $innerCalled = false;
            try {
                $middleware->process($this->context(), function () use (&$innerCalled) {
                    $innerCalled = true;

                    throw new CardDeclined('declined');
                });
                self::fail('Expected exception was not thrown.');
            } catch (CardDeclined) {
            }
            self::assertTrue($innerCalled);
        }
    }

    public function testBreakerStateIsIsolatedPerGateway(): void
    {
        $clock = new FakeClock();
        $cache = new InMemoryCache($clock);
        $breaker = new CircuitBreaker($cache, $clock, failureThreshold: 1, cooldownSeconds: 30);
        $middleware = new CircuitBreakerMiddleware($breaker);

        try {
            $middleware->process($this->context('stripe'), $this->failingWith(new NetworkException('x')));
        } catch (NetworkException) {
        }

        // paypal's breaker is untouched by stripe's trip.
        $innerCalled = false;
        $result = $middleware->process($this->context('paypal'), function () use (&$innerCalled) {
            $innerCalled = true;

            return 'paypal-ok';
        });

        self::assertTrue($innerCalled);
        self::assertSame('paypal-ok', $result);
    }

    public function testSuccessAfterSubThresholdFailuresResetsTheCounter(): void
    {
        [$middleware] = $this->middleware(failureThreshold: 3, cooldownSeconds: 30);

        $this->expectFailureNTimes($middleware, NetworkException::class, 2);
        $middleware->process($this->context(), fn() => 'success');

        // Counter reset to 0: two more failures shouldn't trip a threshold-3 breaker.
        $this->expectFailureNTimes($middleware, NetworkException::class, 2);

        $innerCalled = false;
        $middleware->process($this->context(), function () use (&$innerCalled) {
            $innerCalled = true;

            return 'still-closed';
        });
        self::assertTrue($innerCalled);
    }

    public function testTwoMiddlewareInstancesSharingTheSameCacheObserveConsistentState(): void
    {
        $clock = new FakeClock();
        $cache = new InMemoryCache($clock);
        $middlewareA = new CircuitBreakerMiddleware(new CircuitBreaker($cache, $clock, failureThreshold: 1, cooldownSeconds: 30));
        $middlewareB = new CircuitBreakerMiddleware(new CircuitBreaker($cache, $clock, failureThreshold: 1, cooldownSeconds: 30));

        try {
            $middlewareA->process($this->context(), $this->failingWith(new NetworkException('x')));
        } catch (NetworkException) {
        }

        try {
            $middlewareB->process($this->context(), fn() => self::fail('Inner must not run: B should see the breaker already open.'));
            self::fail('Expected CircuitOpen was not thrown.');
        } catch (CircuitOpen $e) {
            self::assertInstanceOf(CircuitOpen::class, $e);
        }
    }

    public function testCircuitOpenIsNeverFedBackIntoItsOwnFailureCounting(): void
    {
        [$middleware, , $clock] = $this->middleware(failureThreshold: 1, cooldownSeconds: 30);

        $this->expectFailureNTimes($middleware, NetworkException::class, 1);

        // A burst of calls made entirely while open, each short-circuiting on CircuitOpen.
        for ($i = 0; $i < 5; ++$i) {
            try {
                $middleware->process($this->context(), fn() => self::fail('Inner must not run while open.'));
                self::fail('Expected CircuitOpen was not thrown.');
            } catch (CircuitOpen) {
            }
        }

        // If CircuitOpen had been fed back into the failure count/openedAt, the cooldown
        // would have been extended by the burst above. It wasn't: advancing the clock by
        // exactly the *original* cooldown still transitions cleanly to half-open.
        $clock->advance(31);

        $trialCalled = false;
        $result = $middleware->process($this->context(), function () use (&$trialCalled) {
            $trialCalled = true;

            return 'trial-result';
        });

        self::assertTrue($trialCalled);
        self::assertSame('trial-result', $result);
    }

    /**
     * @return array{0: CircuitBreakerMiddleware, 1: CircuitBreaker, 2: FakeClock}
     */
    private function middleware(int $failureThreshold, int $cooldownSeconds): array
    {
        $clock = new FakeClock();
        $cache = new InMemoryCache($clock);
        $breaker = new CircuitBreaker($cache, $clock, $failureThreshold, $cooldownSeconds);

        return [new CircuitBreakerMiddleware($breaker), $breaker, $clock];
    }

    private function failingWith(\Throwable $exception): \Closure
    {
        return function () use ($exception) {
            throw $exception;
        };
    }

    /**
     * @param class-string<\Throwable> $exceptionClass
     */
    private function expectFailureNTimes(CircuitBreakerMiddleware $middleware, string $exceptionClass, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            try {
                $middleware->process($this->context(), $this->failingWith(new $exceptionClass('x')));
                self::fail('Expected exception was not thrown.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($exceptionClass, $e);
            }
        }
    }

    /**
     * @param class-string<\Throwable> $exceptionClass
     */
    private function expectExceptionDuring(CircuitBreakerMiddleware $middleware, string $exceptionClass, \Closure $action): void
    {
        try {
            $action();
            self::fail(sprintf('Expected %s was not thrown.', $exceptionClass));
        } catch (\Throwable $e) {
            self::assertInstanceOf($exceptionClass, $e);
        }
    }

    private function context(string $gateway = 'stripe'): OperationContext
    {
        return new OperationContext(
            gateway: $gateway,
            operation: 'charge',
            request: new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: 'key'),
            capabilities: [],
        );
    }
}
