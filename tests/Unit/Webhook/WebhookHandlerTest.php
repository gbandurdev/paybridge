<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Webhook;

use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\PaymentStatusLookup;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Drivers\Fake\FakeGateway;
use PayBridge\Event\PaymentStatusChanged;
use PayBridge\Event\WebhookIgnoredStale;
use PayBridge\Event\WebhookReceived;
use PayBridge\Exception\InvalidSignature;
use PayBridge\Exception\UnsupportedOperation;
use PayBridge\Store\InMemory\InMemoryEventStore;
use PayBridge\Testing\FakeClock;
use PayBridge\Webhook\WebhookHandler;
use PayBridge\Webhook\WebhookRequest;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class WebhookHandlerTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    public function testValidSignatureProcessesAndDispatchesNonDuplicateWebhookReceived(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), new InMemoryEventStore(new FakeClock()), $dispatcher);

        $event = $handler->handle('fake', $this->webhookRequest(['event_id' => 'evt_1', 'type' => 'payment.updated', 'occurred_at' => '2026-01-01T00:00:01+00:00']));

        self::assertSame('evt_1', $event->eventId);
        $received = $this->only(WebhookReceived::class, $dispatcher->events);
        self::assertFalse($received->duplicate);
    }

    public function testTamperedSignatureThrowsInvalidSignature(): void
    {
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), new InMemoryEventStore(new FakeClock()));

        $payload = json_encode(['event_id' => 'evt_1', 'type' => 'payment.updated', 'occurred_at' => '2026-01-01T00:00:01+00:00'], \JSON_THROW_ON_ERROR);

        $this->expectException(InvalidSignature::class);

        $handler->handle('fake', new WebhookRequest($payload, ['X-Fake-Signature' => 'not-the-real-signature']));
    }

    public function testGatewayWithoutWebhookSupportThrowsUnsupportedOperation(): void
    {
        $gatewayOnly = new class implements Gateway {
            public function name(): string
            {
                return 'core-only';
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
                throw new \LogicException('unused');
            }
        };
        $handler = new WebhookHandler(fn() => $gatewayOnly, new InMemoryEventStore(new FakeClock()));

        $this->expectException(UnsupportedOperation::class);

        $handler->handle('core-only', new WebhookRequest('{}', []));
    }

    public function testSecondDeliveryOfTheSameEventIsADuplicateAndIsNotReprocessed(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $events = new InMemoryEventStore(new FakeClock());
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), $events, $dispatcher);
        $request = $this->webhookRequest(['event_id' => 'evt_1', 'type' => 'payment.updated', 'occurred_at' => '2026-01-01T00:00:01+00:00']);

        $handler->handle('fake', $request);
        $handler->handle('fake', $request);

        $received = array_values(array_filter($dispatcher->events, static fn($e): bool => $e instanceof WebhookReceived));
        self::assertCount(2, $received);
        self::assertFalse($received[0]->duplicate);
        self::assertTrue($received[1]->duplicate);
    }

    public function testProcessingFailureReleasesTheClaimAndRethrowsThenAnImmediateRedeliveryIsClaimedAgain(): void
    {
        $events = new InMemoryEventStore(new FakeClock());
        $explodingLookup = new class implements PaymentStatusLookup {
            public function findStatus(string $gateway, string $providerReference): ?PaymentStatus
            {
                throw new \RuntimeException('lookup exploded');
            }
        };
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), $events, null, $explodingLookup);
        $request = $this->webhookRequest([
            'event_id' => 'evt_1',
            'type' => 'payment.updated',
            'reference' => 'pay_1',
            'status' => 'captured',
            'occurred_at' => '2026-01-01T00:00:01+00:00',
        ]);

        try {
            $handler->handle('fake', $request);
            self::fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('lookup exploded', $e->getMessage());
        }

        self::assertFalse($events->has('fake:evt_1'));

        $dispatcher = $this->collectingDispatcher();
        $handlerWithoutLookup = new WebhookHandler(fn() => $this->fakeGateway(), $events, $dispatcher);
        $handlerWithoutLookup->handle('fake', $request);

        $received = $this->only(WebhookReceived::class, $dispatcher->events);
        self::assertFalse($received->duplicate);
    }

    public function testACrashedWorkerSClaimExpiresAfterTheShortClaimTtlAndIsReclaimedAndProcessed(): void
    {
        $clock = new FakeClock();
        $events = new InMemoryEventStore($clock);

        // Simulates a worker that claimed the event and then crashed before reaching
        // remember()/release() — exactly what a prior WebhookHandler::handle() call claiming
        // with the default 300s processing TTL would have left behind.
        self::assertTrue($events->claim('fake:evt_1', 300));

        $clock->advance(301);

        $dispatcher = $this->collectingDispatcher();
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), $events, $dispatcher);
        $request = $this->webhookRequest(['event_id' => 'evt_1', 'type' => 'payment.updated', 'occurred_at' => '2026-01-01T00:00:01+00:00']);

        $handler->handle('fake', $request);

        $received = $this->only(WebhookReceived::class, $dispatcher->events);
        self::assertFalse($received->duplicate);
    }

    public function testNoStatusLookupConfiguredDispatchesOnlyWebhookReceived(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), new InMemoryEventStore(new FakeClock()), $dispatcher);

        $handler->handle('fake', $this->webhookRequest([
            'event_id' => 'evt_1',
            'type' => 'payment.updated',
            'reference' => 'pay_1',
            'status' => 'captured',
            'occurred_at' => '2026-01-01T00:00:01+00:00',
        ]));

        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(WebhookReceived::class, $dispatcher->events[0]);
    }

    public function testStatusLookupReturningNullSkipsStatusDiffing(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $lookup = $this->lookupReturning(null);
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), new InMemoryEventStore(new FakeClock()), $dispatcher, $lookup);

        $handler->handle('fake', $this->webhookRequest([
            'event_id' => 'evt_1',
            'type' => 'payment.updated',
            'reference' => 'pay_1',
            'status' => 'captured',
            'occurred_at' => '2026-01-01T00:00:01+00:00',
        ]));

        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(WebhookReceived::class, $dispatcher->events[0]);
    }

    public function testValidTransitionDispatchesPaymentStatusChanged(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $lookup = $this->lookupReturning(PaymentStatus::AUTHORIZED);
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), new InMemoryEventStore(new FakeClock()), $dispatcher, $lookup);

        $handler->handle('fake', $this->webhookRequest([
            'event_id' => 'evt_1',
            'type' => 'payment.updated',
            'reference' => 'pay_1',
            'status' => 'captured',
            'occurred_at' => '2026-01-01T00:00:01+00:00',
        ]));

        $changed = $this->only(PaymentStatusChanged::class, $dispatcher->events);
        self::assertSame('fake', $changed->gateway);
        self::assertSame('pay_1', $changed->providerReference);
        self::assertSame(PaymentStatus::AUTHORIZED, $changed->previousStatus);
        self::assertSame(PaymentStatus::CAPTURED, $changed->newStatus);
        self::assertNull($changed->payment);
    }

    public function testStaleTransitionDispatchesWebhookIgnoredStaleInsteadOfPaymentStatusChanged(): void
    {
        $dispatcher = $this->collectingDispatcher();
        $events = new InMemoryEventStore(new FakeClock());
        // REFUNDED -> CAPTURED is not a legal StateMachine transition.
        $lookup = $this->lookupReturning(PaymentStatus::REFUNDED);
        $handler = new WebhookHandler(fn() => $this->fakeGateway(), $events, $dispatcher, $lookup);
        $request = $this->webhookRequest([
            'event_id' => 'evt_1',
            'type' => 'payment.updated',
            'reference' => 'pay_1',
            'status' => 'captured',
            'occurred_at' => '2026-01-01T00:00:01+00:00',
        ]);

        $handler->handle('fake', $request);

        $ignored = $this->only(WebhookIgnoredStale::class, $dispatcher->events);
        self::assertSame(PaymentStatus::REFUNDED, $ignored->knownStatus);
        self::assertSame('evt_1', $ignored->event->eventId);

        // Still claimed/remembered — a redelivery of the same stale event must be treated as a
        // duplicate (ack with 200), never reprocessed or retried forever.
        $handler->handle('fake', $request);
        $received = array_values(array_filter($dispatcher->events, static fn($e): bool => $e instanceof WebhookReceived));
        self::assertCount(2, $received);
        self::assertFalse($received[0]->duplicate);
        self::assertTrue($received[1]->duplicate);
    }

    private function fakeGateway(): FakeGateway
    {
        return new FakeGateway(name: 'fake', capabilities: FakeGateway::DEFAULT_CAPABILITIES, webhookSecret: self::SECRET);
    }

    /**
     * @param array<string, mixed> $payloadData
     */
    private function webhookRequest(array $payloadData): WebhookRequest
    {
        $payload = json_encode($payloadData, \JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, self::SECRET);

        return new WebhookRequest($payload, ['X-Fake-Signature' => $signature]);
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
