<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Middleware;

use PayBridge\Domain\Money;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\IdempotencyConflict;
use PayBridge\Exception\IdempotencyConflictReason;
use PayBridge\Exception\NetworkException;
use PayBridge\Middleware\IdempotencyMiddleware;
use PayBridge\Middleware\OperationContext;
use PayBridge\Store\InMemory\InMemoryIdempotencyStore;
use PayBridge\Store\InMemory\InMemoryLockStore;
use PayBridge\Testing\FakeClock;
use PayBridge\Testing\FakeSleeper;
use PHPUnit\Framework\TestCase;

final class IdempotencyMiddlewareTest extends TestCase
{
    public function testNoExistingRecordCallsInnerOnceAndStoresCompletedRecord(): void
    {
        [$middleware, $store] = $this->middleware();
        $calls = 0;

        $result = $middleware->process($this->context('order-1'), function () use (&$calls) {
            ++$calls;

            return $this->payment();
        });

        self::assertSame(1, $calls);
        self::assertInstanceOf(Payment::class, $result);
        $record = $store->get('idem:stripe:order-1');
        self::assertIsArray($record);
        self::assertSame('completed', $record['status']);
    }

    public function testSameKeySameFingerprintCompletedRecordReturnsCachedResultWithoutCallingInner(): void
    {
        [$middleware] = $this->middleware();

        $middleware->process($this->context('order-1'), fn() => $this->payment());

        $calls = 0;
        $result = $middleware->process($this->context('order-1'), function () use (&$calls) {
            ++$calls;

            return $this->payment();
        });

        self::assertSame(0, $calls);
        self::assertInstanceOf(Payment::class, $result);
        self::assertSame('pay_1', $result->providerReference);
    }

    public function testSameKeyDifferentFingerprintThrowsPayloadMismatch(): void
    {
        [$middleware] = $this->middleware();

        $middleware->process($this->context('order-1', '10.00'), fn() => $this->payment());

        try {
            $middleware->process($this->context('order-1', '20.00'), fn() => self::fail('Inner must not be called.'));
            self::fail('Expected IdempotencyConflict was not thrown.');
        } catch (IdempotencyConflict $e) {
            self::assertSame(IdempotencyConflictReason::PAYLOAD_MISMATCH, $e->reason());
        }
    }

    public function testSameKeySameFingerprintFailedRecordFromNonRetryableReplaysTheExactException(): void
    {
        [$middleware] = $this->middleware();

        try {
            $middleware->process($this->context('order-1'), function () {
                throw new CardDeclined('declined', gateway: 'stripe', operation: 'charge', providerCode: 'insufficient_funds');
            });
        } catch (CardDeclined) {
        }

        try {
            $middleware->process($this->context('order-1'), fn() => self::fail('Inner must not be called on replay.'));
            self::fail('Expected CardDeclined was not thrown.');
        } catch (CardDeclined $e) {
            self::assertSame('declined', $e->getMessage());
            self::assertSame('insufficient_funds', $e->providerCode());
        }
    }

    public function testRetryableFailureStoresNothingAndASecondCallTriesAgainFromScratch(): void
    {
        [$middleware, $store] = $this->middleware();

        try {
            $middleware->process($this->context('order-1'), function () {
                throw new NetworkException('transient outage');
            });
        } catch (NetworkException) {
        }

        self::assertNull($store->get('idem:stripe:order-1'));

        $calls = 0;
        $result = $middleware->process($this->context('order-1'), function () use (&$calls) {
            ++$calls;

            return $this->payment();
        });

        self::assertSame(1, $calls);
        self::assertInstanceOf(Payment::class, $result);
    }

    public function testNonRetryableFailureStoresAFailedRecordAndReplaysOnTheSecondCall(): void
    {
        [$middleware, $store] = $this->middleware();

        try {
            $middleware->process($this->context('order-1'), fn() => throw new CardDeclined('declined'));
        } catch (CardDeclined) {
        }

        $record = $store->get('idem:stripe:order-1');
        self::assertIsArray($record);
        self::assertSame('failed', $record['status']);

        $calls = 0;
        try {
            $middleware->process($this->context('order-1'), function () use (&$calls) {
                ++$calls;

                return $this->payment();
            });
            self::fail('Expected CardDeclined was not thrown.');
        } catch (CardDeclined) {
        }
        self::assertSame(0, $calls);
    }

    public function testConcurrentSameKeySecondCallerGetsCachedResultOnceFirstCompletes(): void
    {
        $clock = new FakeClock();
        $lock = new InMemoryLockStore($clock);
        $store = new InMemoryIdempotencyStore($clock);
        $key = 'idem:stripe:order-1';

        // Simulate the "first caller" already holding the lock.
        $firstToken = $lock->acquire($key, 30);
        self::assertNotNull($firstToken);

        $fingerprint = \PayBridge\Resilience\RequestFingerprint::compute($this->paymentRequest('order-1'));

        // A sleeper that, the first time it's asked to wait, simulates the first caller
        // finishing mid-wait: stores the completed record and releases its lock.
        $sleeper = new class ($clock, $lock, $store, $key, $firstToken, $fingerprint) implements \PayBridge\Resilience\Sleeper {
            private bool $released = false;

            public function __construct(
                private readonly FakeClock $clock,
                private readonly InMemoryLockStore $lock,
                private readonly InMemoryIdempotencyStore $store,
                private readonly string $key,
                private readonly string $firstToken,
                private readonly string $fingerprint,
            ) {}

            public function sleep(int $milliseconds): void
            {
                $this->clock->advanceMilliseconds($milliseconds);

                if (!$this->released) {
                    $this->released = true;
                    $this->store->put($this->key, [
                        'fingerprint' => $this->fingerprint,
                        'status' => 'completed',
                        'response' => (new Payment(
                            providerReference: 'pay_first',
                            status: PaymentStatus::CAPTURED,
                            amount: Money::of('10.00', 'EUR'),
                        ))->toArray(),
                    ], 86400);
                    $this->lock->release($this->key, $this->firstToken);
                }
            }
        };

        $middleware = new IdempotencyMiddleware($store, $lock, $sleeper, lockTtlSeconds: 30, lockPollIntervalMs: 50);

        $result = $middleware->process($this->context('order-1'), fn() => self::fail('Inner must not run: should get the cached result.'));

        self::assertInstanceOf(Payment::class, $result);
        self::assertSame('pay_first', $result->providerReference);
    }

    public function testConcurrentSameKeySecondCallerGetsInProgressWhenFirstNeverFinishes(): void
    {
        $clock = new FakeClock();
        $lock = new InMemoryLockStore($clock);
        $store = new InMemoryIdempotencyStore($clock);
        $sleeper = new FakeSleeper($clock);

        // Held with a much longer TTL than the waiter's own budget below, so it's still
        // valid throughout the wait — simulating a holder that never finishes, as opposed
        // to one that simply crashed and will go stale (and become stealable) soon.
        $lock->acquire('idem:stripe:order-1', 10_000);

        $middleware = new IdempotencyMiddleware($store, $lock, $sleeper, lockTtlSeconds: 30, lockPollIntervalMs: 50);

        try {
            $middleware->process($this->context('order-1'), fn() => self::fail('Inner must not be called.'));
            self::fail('Expected IdempotencyConflict was not thrown.');
        } catch (IdempotencyConflict $e) {
            self::assertSame(IdempotencyConflictReason::IN_PROGRESS, $e->reason());
        }

        self::assertGreaterThanOrEqual(30_000, $sleeper->totalMilliseconds());
    }

    public function testInnerThrowsLockIsStillReleasedAfterward(): void
    {
        $clock = new FakeClock();
        $lock = new InMemoryLockStore($clock);
        $store = new InMemoryIdempotencyStore($clock);
        $sleeper = new FakeSleeper($clock);
        $middleware = new IdempotencyMiddleware($store, $lock, $sleeper, lockTtlSeconds: 30, lockPollIntervalMs: 50);

        try {
            $middleware->process($this->context('order-1'), fn() => throw new CardDeclined('declined'));
        } catch (CardDeclined) {
        }

        // The lock for this exact key must be free again — proves it was released, not
        // left dangling for the full TTL.
        self::assertNotNull($lock->acquire('idem:stripe:order-1', 30));
    }

    public function testFindHasNoIdempotencyKeyAndBypassesTheMiddlewareEntirely(): void
    {
        [$middleware] = $this->middleware();

        $context = new OperationContext(gateway: 'stripe', operation: 'find', request: null, capabilities: []);

        $calls = 0;
        $result = $middleware->process($context, function () use (&$calls) {
            ++$calls;

            return $this->payment();
        });

        self::assertSame(1, $calls);
        self::assertInstanceOf(Payment::class, $result);
    }

    public function testSameIdempotencyKeyOnDifferentGatewaysAreIndependent(): void
    {
        [$middleware, $store] = $this->middleware();

        $middleware->process($this->context('order-1', gateway: 'stripe'), fn() => $this->payment());

        $calls = 0;
        $middleware->process($this->context('order-1', gateway: 'paypal'), function () use (&$calls) {
            ++$calls;

            return $this->payment();
        });

        self::assertSame(1, $calls);
        self::assertNotNull($store->get('idem:stripe:order-1'));
        self::assertNotNull($store->get('idem:paypal:order-1'));
    }

    public function testRecordExpiresAfterTtlAndARepeatCallTriesInnerAgain(): void
    {
        $clock = new FakeClock();
        $lock = new InMemoryLockStore($clock);
        $store = new InMemoryIdempotencyStore($clock);
        $sleeper = new FakeSleeper($clock);
        $middleware = new IdempotencyMiddleware($store, $lock, $sleeper, lockTtlSeconds: 30, recordTtlSeconds: 60, lockPollIntervalMs: 50);

        $middleware->process($this->context('order-1'), fn() => $this->payment());
        $clock->advance(61);

        $calls = 0;
        $middleware->process($this->context('order-1'), function () use (&$calls) {
            ++$calls;

            return $this->payment();
        });

        self::assertSame(1, $calls);
    }

    public function testUnresolvableStoredExceptionClassFallsBackGracefullyOnReplay(): void
    {
        $clock = new FakeClock();
        $lock = new InMemoryLockStore($clock);
        $store = new InMemoryIdempotencyStore($clock);
        $sleeper = new FakeSleeper($clock);
        $middleware = new IdempotencyMiddleware($store, $lock, $sleeper);

        $context = $this->context('order-1');
        $fingerprint = \PayBridge\Resilience\RequestFingerprint::compute($this->paymentRequest('order-1'));

        $store->put('idem:stripe:order-1', [
            'fingerprint' => $fingerprint,
            'status' => 'failed',
            'error' => ['class' => 'NoLongerExists', 'data' => ['message' => 'stale record']],
        ], 86400);

        try {
            $middleware->process($context, fn() => self::fail('Inner must not be called on replay.'));
            self::fail('Expected a PaymentException was not thrown.');
        } catch (\PayBridge\Exception\PaymentException $e) {
            self::assertSame('stale record', $e->getMessage());
        }
    }

    /**
     * @return array{0: IdempotencyMiddleware, 1: InMemoryIdempotencyStore}
     */
    private function middleware(): array
    {
        $clock = new FakeClock();
        $lock = new InMemoryLockStore($clock);
        $store = new InMemoryIdempotencyStore($clock);
        $sleeper = new FakeSleeper($clock);

        return [new IdempotencyMiddleware($store, $lock, $sleeper, lockTtlSeconds: 30, lockPollIntervalMs: 50), $store];
    }

    private function context(string $idempotencyKey, string $amount = '10.00', string $gateway = 'stripe'): OperationContext
    {
        return new OperationContext(
            gateway: $gateway,
            operation: 'charge',
            request: $this->paymentRequest($idempotencyKey, $amount),
            capabilities: [],
        );
    }

    private function paymentRequest(string $idempotencyKey, string $amount = '10.00'): PaymentRequest
    {
        return new PaymentRequest(amount: Money::of($amount, 'EUR'), idempotencyKey: $idempotencyKey);
    }

    private function payment(): Payment
    {
        return new Payment(providerReference: 'pay_1', status: PaymentStatus::CAPTURED, amount: Money::of('10.00', 'EUR'));
    }
}
