<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Reconciliation;

use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\PaymentStatusLookup;
use PayBridge\Domain\Money;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Event\PaymentStatusChanged;
use PayBridge\Reconciliation\ReconciliationCandidate;
use PayBridge\Reconciliation\Reconciler;
use PayBridge\Testing\FakeClock;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;

final class ReconcilerTest extends TestCase
{
    public function testUnknownPreviousStatusAlwaysDispatchesAndReturnsTheFreshPayment(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $reconciler = new Reconciler(fn() => $this->gatewayReturning(PaymentStatus::CAPTURED), new FakeClock(), $dispatcher);

        $changed = $reconciler->reconcile('fake', [new ReconciliationCandidate('pay_1', new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))]);

        self::assertCount(1, $changed);
        self::assertSame('pay_1', $changed[0]->providerReference);

        $event = $this->only(PaymentStatusChanged::class, $dispatcher->events);
        self::assertNull($event->previousStatus);
        self::assertSame(PaymentStatus::CAPTURED, $event->newStatus);
        self::assertSame($changed[0], $event->payment);
    }

    public function testSameStatusAsLookupIsSkipped(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $lookup = $this->lookupReturning(PaymentStatus::CAPTURED);
        $reconciler = new Reconciler(fn() => $this->gatewayReturning(PaymentStatus::CAPTURED), new FakeClock(), $dispatcher, $lookup);

        $changed = $reconciler->reconcile('fake', [new ReconciliationCandidate('pay_1', new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))]);

        self::assertSame([], $changed);
        self::assertSame([], $dispatcher->events);
    }

    public function testLegalTransitionDispatchesPaymentStatusChanged(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $lookup = $this->lookupReturning(PaymentStatus::AUTHORIZED);
        $reconciler = new Reconciler(fn() => $this->gatewayReturning(PaymentStatus::CAPTURED), new FakeClock(), $dispatcher, $lookup);

        $changed = $reconciler->reconcile('fake', [new ReconciliationCandidate('pay_1', new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))]);

        self::assertCount(1, $changed);
        $event = $this->only(PaymentStatusChanged::class, $dispatcher->events);
        self::assertSame(PaymentStatus::AUTHORIZED, $event->previousStatus);
        self::assertSame(PaymentStatus::CAPTURED, $event->newStatus);
    }

    public function testIllegalTransitionIsSkippedNotReported(): void
    {
        $dispatcher = $this->collectingDispatcher();
        // REFUNDED -> CAPTURED is not a legal StateMachine transition.
        $lookup = $this->lookupReturning(PaymentStatus::REFUNDED);
        $reconciler = new Reconciler(fn() => $this->gatewayReturning(PaymentStatus::CAPTURED), new FakeClock(), $dispatcher, $lookup);

        $changed = $reconciler->reconcile('fake', [new ReconciliationCandidate('pay_1', new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))]);

        self::assertSame([], $changed);
        self::assertSame([], $dispatcher->events);
    }

    public function testOlderThanMinutesSkipsCandidatesNotOldEnough(): void
    {
        $clock = new FakeClock(new \DateTimeImmutable('2026-01-01T00:10:00+00:00'));
        $dispatcher = $this->collectingDispatcher();
        $reconciler = new Reconciler(fn() => $this->gatewayReturning(PaymentStatus::CAPTURED), $clock, $dispatcher);

        // Only 5 minutes old; olderThanMinutes requires 30.
        $recent = new ReconciliationCandidate('pay_recent', new \DateTimeImmutable('2026-01-01T00:05:00+00:00'));

        $changed = $reconciler->reconcile('fake', [$recent], olderThanMinutes: 30);

        self::assertSame([], $changed);
    }

    public function testOneCandidatesFindFailureIsLoggedAndSkippedWithoutAbortingTheRest(): void
    {
        $gateway = new class implements Gateway {
            public function name(): string
            {
                return 'fake';
            }

            public function capabilities(): array
            {
                return [];
            }

            public function charge(PaymentRequest $request): Payment
            {
                throw new \LogicException('unused');
            }

            public function find(string $providerReference): Payment
            {
                if ($providerReference === 'pay_bad') {
                    throw new \RuntimeException('provider unreachable');
                }

                return new Payment(providerReference: $providerReference, status: PaymentStatus::CAPTURED, amount: Money::of('10.00', 'EUR'));
            }
        };

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $reconciler = new Reconciler(fn() => $gateway, new FakeClock(), null, null, $logger);

        $changed = $reconciler->reconcile('fake', [
            new ReconciliationCandidate('pay_bad', new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
            new ReconciliationCandidate('pay_good', new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        ]);

        self::assertCount(1, $changed);
        self::assertSame('pay_good', $changed[0]->providerReference);
        self::assertCount(1, $logger->records);
    }

    private function gatewayReturning(PaymentStatus $status): Gateway
    {
        return new class ($status) implements Gateway {
            public function __construct(private readonly PaymentStatus $status) {}

            public function name(): string
            {
                return 'fake';
            }

            public function capabilities(): array
            {
                return [];
            }

            public function charge(PaymentRequest $request): Payment
            {
                throw new \LogicException('unused');
            }

            public function find(string $providerReference): Payment
            {
                return new Payment(providerReference: $providerReference, status: $this->status, amount: Money::of('10.00', 'EUR'));
            }
        };
    }

    private function lookupReturning(?PaymentStatus $status): PaymentStatusLookup
    {
        return new class ($status) implements PaymentStatusLookup {
            public function __construct(private readonly ?PaymentStatus $status) {}

            public function findStatus(string $gateway, string $providerReference): ?PaymentStatus
            {
                return $this->status;
            }
        };
    }

    /**
     * @return EventDispatcherInterface&object{events: list<object>}
     */
    private function collectingDispatcher(): EventDispatcherInterface
    {
        return new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param list<object> $events
     * @return T
     */
    private function only(string $class, array $events): object
    {
        $matches = array_values(array_filter($events, static fn(object $e): bool => $e instanceof $class));
        self::assertCount(1, $matches, sprintf('Expected exactly one %s to be dispatched.', $class));

        /** @var T */
        return $matches[0];
    }
}
