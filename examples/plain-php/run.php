<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PayBridge\Clock\SystemClock;
use PayBridge\Contracts\PaymentStatusLookup;
use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Domain\RefundRequest;
use PayBridge\Event\PaymentStatusChanged;
use PayBridge\Event\WebhookIgnoredStale;
use PayBridge\Event\WebhookReceived;
use PayBridge\PaymentManager;
use PayBridge\Reconciliation\ReconciliationCandidate;
use PayBridge\Store\Pdo\Migrator;
use PayBridge\Store\Pdo\PdoEventStore;
use PayBridge\Store\Pdo\PdoIdempotencyStore;
use PayBridge\Store\Pdo\PdoLockStore;
use PayBridge\Store\Pdo\Pruner;
use PayBridge\Testing\FakeClock;
use PayBridge\Webhook\WebhookRequest;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A mutable, in-memory stand-in for an app's own "what status do I think this payment is in"
 * storage (docs/PLAN.md: PayBridge itself keeps no payment ledger). WebhookHandler and
 * Reconciler both consult this to decide whether an incoming status is a real, legal change.
 */
final class DemoStatusLookup implements PaymentStatusLookup
{
    /** @var array<string, PaymentStatus> */
    private array $statuses = [];

    public function findStatus(string $gateway, string $providerReference): ?PaymentStatus
    {
        return $this->statuses[$providerReference] ?? null;
    }

    public function remember(string $providerReference, PaymentStatus $status): void
    {
        $this->statuses[$providerReference] = $status;
    }
}

$driver = getenv('PAYBRIDGE_STORE_DRIVER');
$driver = \is_string($driver) && $driver !== '' ? $driver : 'pdo';

$dataDir = __DIR__ . '/data';
$sqlitePath = $dataDir . '/paybridge.sqlite';

$storeConfig = match ($driver) {
    'pdo' => ['driver' => 'pdo', 'dsn' => 'sqlite:' . $sqlitePath],
    'redis' => ['driver' => 'redis', 'dsn' => getenv('PAYBRIDGE_REDIS_DSN') ?: 'redis://127.0.0.1:6379'],
    default => throw new InvalidArgumentException(sprintf('Unknown PAYBRIDGE_STORE_DRIVER "%s"; use "pdo" or "redis".', $driver)),
};

printf("PayBridge plain-php example — store driver: %s\n\n", $driver);

// Stores are persistent (SQLite file / Redis), so a unique suffix per run keeps every
// idempotency key, event id and order id fresh — otherwise a second run against the same
// backend would just replay the first run's cached results end-to-end (correct behavior, but
// it hides the rest of the walkthrough behind idempotency replay instead of demonstrating it).
$runId = bin2hex(random_bytes(4));

$statusLookup = new DemoStatusLookup();

// Stands in for the app's own code: whenever PayBridge observes a real status change (via a
// webhook or Reconciler), the app updates its own storage — here, just $statusLookup.
$dispatcher = new class($statusLookup) implements EventDispatcherInterface {
    public function __construct(private readonly DemoStatusLookup $statusLookup) {}

    public function dispatch(object $event): object
    {
        if ($event instanceof PaymentStatusChanged) {
            $this->statusLookup->remember($event->providerReference, $event->newStatus);
        }

        $summary = match (true) {
            $event instanceof WebhookReceived => sprintf('duplicate=%s', $event->duplicate ? 'yes' : 'no'),
            $event instanceof WebhookIgnoredStale => sprintf('known=%s', $event->knownStatus->value),
            $event instanceof PaymentStatusChanged => sprintf(
                '%s -> %s',
                $event->previousStatus?->value ?? 'unknown',
                $event->newStatus->value,
            ),
            default => '',
        };

        printf("  [event] %s %s\n", (new ReflectionClass($event))->getShortName(), $summary);

        return $event;
    }
};

$payments = PaymentManager::fromConfig([
    'default' => 'fake',
    'gateways' => [
        'fake' => ['driver' => 'fake', 'webhook_secret' => 'demo-secret'],
    ],
    'stores' => [
        'idempotency' => $storeConfig,
        'lock' => $storeConfig,
        'events' => $storeConfig,
        // The circuit breaker needs a PSR-16 CacheInterface specifically; neither the "pdo"
        // nor the "redis" store driver has one lying around (see Store\StoreFactory::cache()),
        // so it must always be configured explicitly whenever idempotency/lock/events use
        // "pdo" or "redis". A real multi-worker deployment would point this at something
        // shared (e.g. a "cache" driver wrapping a real PSR-16 Redis/Memcached client) instead
        // of "inmemory", to keep docs/PLAN.md §6.4's "shared across workers" guarantee.
        'cache' => ['driver' => 'inmemory'],
    ],
], events: $dispatcher, statusLookup: $statusLookup);

// 1. Charge.
printf("1) Charging EUR 49.99 (order-1001-%s)...\n", $runId);
$payment = $payments->gateway('fake')->charge(new PaymentRequest(
    amount: Money::of('49.99', 'EUR'),
    idempotencyKey: "order-1001-{$runId}-charge",
    orderId: "order-1001-{$runId}",
));
$statusLookup->remember($payment->providerReference, $payment->status);
printf("   -> reference=%s status=%s\n\n", $payment->providerReference, $payment->status->value);

// 2. Idempotency proof: the identical call must not charge a second time.
echo "2) Repeating the exact same charge call (same idempotency key)...\n";
$repeat = $payments->gateway('fake')->charge(new PaymentRequest(
    amount: Money::of('49.99', 'EUR'),
    idempotencyKey: "order-1001-{$runId}-charge",
    orderId: "order-1001-{$runId}",
));
printf(
    "   -> reference=%s (%s the original — no second provider-side charge happened)\n\n",
    $repeat->providerReference,
    $repeat->providerReference === $payment->providerReference ? 'same as' : 'DIFFERENT FROM',
);

// 3. Partial refund.
echo "3) Partially refunding EUR 10.00...\n";
$refund = $payments->gateway('fake')->refund(new RefundRequest(
    paymentReference: $payment->providerReference,
    idempotencyKey: "order-1001-{$runId}-refund-1",
    amount: Money::of('10.00', 'EUR'),
));
$statusLookup->remember($payment->providerReference, $refund->status);
printf("   -> refund status=%s\n\n", $refund->status->value);

// 4. Webhook dedup: the same event id delivered twice.
echo "4) Delivering a webhook, then redelivering the same event id...\n";
$webhookSecret = 'demo-secret';
$eventPayload = static fn(array $data): string => json_encode($data, JSON_THROW_ON_ERROR);
$sign = static fn(string $payload): string => hash_hmac('sha256', $payload, $webhookSecret);

$firstPayload = $eventPayload([
    'event_id' => "evt-{$runId}-1001",
    'type' => 'payment.updated',
    'reference' => $payment->providerReference,
    'status' => PaymentStatus::REFUNDED->value,
    'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
]);
$payments->webhookHandler()->handle('fake', new WebhookRequest($firstPayload, ['X-Fake-Signature' => $sign($firstPayload)]));
$payments->webhookHandler()->handle('fake', new WebhookRequest($firstPayload, ['X-Fake-Signature' => $sign($firstPayload)]));
echo "\n";

// 5. A stale webhook: REFUNDED is terminal (Status\StateMachine has no outgoing edge from it),
// so a late CAPTURED event arriving after step 4 is illegal and must be ignored, not applied.
echo "5) Delivering a stale webhook (CAPTURED, after the payment was already refunded)...\n";
$stalePayload = $eventPayload([
    'event_id' => "evt-{$runId}-1002",
    'type' => 'payment.updated',
    'reference' => $payment->providerReference,
    'status' => PaymentStatus::CAPTURED->value,
    'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
]);
$payments->webhookHandler()->handle('fake', new WebhookRequest($stalePayload, ['X-Fake-Signature' => $sign($stalePayload)]));
echo "\n";

// 6. Reconciliation: a payment left in REQUIRES_ACTION, checked twice.
echo "6) Reconciling a payment stuck in REQUIRES_ACTION...\n";
$redirectPayment = $payments->gateway('fake')->charge(new PaymentRequest(
    amount: Money::of('25.00', 'EUR'),
    idempotencyKey: "order-1002-{$runId}-charge",
    orderId: "order-1002-{$runId}",
    metadata: ['fake_trigger' => 'redirect'],
));
$statusLookup->remember($redirectPayment->providerReference, $redirectPayment->status);
printf("   -> reference=%s status=%s\n", $redirectPayment->providerReference, $redirectPayment->status->value);

$candidate = new ReconciliationCandidate($redirectPayment->providerReference, new DateTimeImmutable('-1 hour'));
echo "   First reconcile (nothing changed yet):\n";
$changed = $payments->reconciler()->reconcile('fake', [$candidate]);
printf("   -> %d payment(s) changed\n", \count($changed));

// Simulate the redirect flow completing via a direct capture.
$payments->gateway('fake')->capture(new CaptureRequest(
    paymentReference: $redirectPayment->providerReference,
    idempotencyKey: "order-1002-{$runId}-capture",
));
echo "   Second reconcile (after the redirect completed):\n";
$changed = $payments->reconciler()->reconcile('fake', [$candidate]);
printf("   -> %d payment(s) changed\n\n", \count($changed));

// 7. Pruner (PDO only): demonstrates the housekeeping job a cron entry would run periodically.
if ($driver === 'pdo') {
    echo "7) Running Pruner::pruneAll() (PDO stores only)...\n";
    $pdo = new PDO('sqlite:' . $sqlitePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    Migrator::migrate($pdo);

    // Insert one deliberately-already-expired record so pruneAll() has something to remove.
    $expiredClock = new FakeClock(new DateTimeImmutable('-1 day'));
    (new PdoIdempotencyStore($pdo, $expiredClock))->put("idem:fake:demo-expired-{$runId}", ['fingerprint' => str_repeat('a', 64), 'status' => 'completed'], 1);

    $pruner = new Pruner(
        new PdoIdempotencyStore($pdo, new SystemClock()),
        new PdoLockStore($pdo, new SystemClock()),
        new PdoEventStore($pdo, new SystemClock()),
    );
    $counts = $pruner->pruneAll();
    printf("   -> purged: idempotency=%d locks=%d events=%d\n\n", $counts['idempotency'], $counts['locks'], $counts['events']);
}

echo "Done.\n";
