# Phase 4 — Manager, webhooks, persistent stores: design

## Context

Phases 0–3 are done per `docs/PROGRESS.md`: domain/contracts, the full resilience pipeline
(`Pipeline`, six middlewares, `RetryPolicy`, backoff, `CircuitBreaker`, `RequestFingerprint`,
in-memory stores), and `FakeGateway` + `PipelineGateway` + `GatewayContractTestCase` all pass
their DoDs. This is Phase 4, scoped by `docs/PLAN.md` §12 exactly:

> `PaymentManager::fromConfig()`, gateway registry (`extend('name', fn($config) => new
> MyGateway(...))`), `withFallback()`.
> `WebhookHandler` with dedup.
> Stores: PSR-16 cache adapter (zero-infra default), PDO (SQLite/PostgreSQL/MySQL) and Redis,
> plus SQL migrations (idempotency table with a unique `(gateway, key)` constraint and an
> `expires_at` index).
> A `Reconciler` that finds `PENDING`/unknown payments older than N minutes and calls `find()`
> to sync their status (meant to run from cron).
> **DoD:** end-to-end example in `examples/plain-php` works with FakeGateway on SQLite and on
> Redis.

Phase 1/2 interfaces are revisited in exactly **one**, user-approved, additive way:
`Contracts\EventStore` gains `claim()`/`release()` (§3a) to close a real duplicate-processing gap
that plain `has()`/`remember()` can't close. `IdempotencyStore`/`LockStore` ship unchanged.
`PipelineGateway` (Phase 3) is reused as-is, exactly per its own docblock's stated purpose.

Two spec-text tensions, both resolved during design review:
1. §6.4 parenthetically calls `withFallback()` "phase 5", but §12's literal Phase 4 checklist
   names it — built now, per §12.
2. §6.6 wants webhooks to ignore stale transitions via the state machine, but PayBridge has no
   payment ledger of its own anywhere in its directory layout. Resolved via a new optional
   `Contracts\PaymentStatusLookup` the app can implement — see §3.

Three corrections came out of user review of an earlier draft of this design and are folded in
throughout, each marked **(review fix)** at its section: `FallbackGateway`'s fallthrough rule was
narrowed to only the two cases provably safe from a double charge; `WebhookHandler`'s dedup was
redesigned around a short processing claim that only gets extended to the long-term dedup window
after success, so a crashed worker doesn't block redelivery for a week; the Laravel/Symfony
bridges (Phase 5) were told in `docs/PLAN.md` itself to use their framework's own lock primitive,
never `CacheLockStore`, by default; and the PDO stores gained `purgeExpired()` plus a `Pruner`.
`GatewayConfig` also validates gateway names.

---

## 1. `PaymentManager` + gateway registry + `Config/` DTOs

### `Config/GatewayConfig.php`

```php
final readonly class GatewayConfig
{
    public function __construct(
        public string $name,
        public string $driver,
        /** @var array<string, mixed> */
        public array $options,
        public ?LoggerInterface $logger = null,
        public ?ClientInterface $httpClient = null,
    ) {}

    public static function fromArray(string $name, array $raw, ?LoggerInterface $logger, ?ClientInterface $httpClient): self
    {
        if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Gateway name "%s" must match /^[a-z0-9_-]+$/.', $name));
        }
        $driver = $raw['driver'] ?? throw new \InvalidArgumentException(sprintf('Gateway "%s" is missing a "driver" key.', $name));
        unset($raw['driver']);
        return new self($name, $driver, $raw, $logger, $httpClient);
    }
}
```

**Decision — bundle `logger`/`httpClient` into `GatewayConfig` rather than passing them as extra
factory arguments.** §12/§5's literal example is a single-argument closure:
`fn($config) => new MyGateway(...)`. Bundling keeps `extend()`'s factory signature to exactly one
parameter. No driver built *in this phase* (only `FakeGateway`) actually uses `httpClient` — it
exists so a user's own `extend()`-registered driver can build a real PSR-18 call today, without
`PaymentManager` needing to know anything about HTTP.

**Decision (review fix) — gateway name validated against `/^[a-z0-9_-]+$/`.** The name ends up in
the webhook route `/_paybridge/webhook/{gateway}` (§11.3) and in log/metric labels; a bad name
(uppercase, spaces, punctuation) should fail at `fromConfig()` time, not silently produce a
gateway nobody can address correctly later.

### `Config/ResilienceConfig.php`

```php
final readonly class ResilienceConfig
{
    public function __construct(
        public int $timeoutMs = 10_000,
        public int $retryMaxAttempts = 4,
        public int $retryBaseMs = 200,
        public int $retryMaxMs = 5_000,
        public int $circuitFailureThreshold = 5,
        public int $circuitCooldownSeconds = 30,
        public int $idempotencyLockTtlSeconds = 30,
        public int $idempotencyRecordTtlSeconds = 86_400,
    ) {}

    public static function fromArray(array $raw): self { /* resilience.timeout_ms, resilience.retry.*, resilience.circuit_breaker.*, defaults above; unrecognized retry.jitter value throws */ }
}
```

**Decision — one global `ResilienceConfig`, no per-gateway override.** §5's example shows exactly
one top-level `'resilience'` block applied uniformly to every `PipelineGateway`'s middleware list.
Per-gateway overrides are not in PLAN.md's example; left for a future enhancement.

### `PaymentManager`

```php
final class PaymentManager
{
    public static function fromConfig(
        array $config,
        ?LoggerInterface $logger = null,
        ?ClientInterface $httpClient = null,
        ?EventDispatcherInterface $events = null,
        ?PaymentStatusLookup $statusLookup = null,
    ): self { /* ... */ }

    public function extend(string $driver, callable $factory): static;
    public function gateway(string $name): Gateway;
    public function default(): Gateway;
    public function withFallback(array $names): Gateway;
    public function webhookHandler(): WebhookHandler;
    public function reconciler(): Reconciler;
}
```

`fromConfig()` builds one `SystemClock`/`RealSleeper`/`SystemRandomSource`, one
`ResilienceConfig`, the stores from `config['stores']` via `Store\StoreFactory` (switches on
`driver: inmemory|cache|pdo|redis`), the six-middleware list in the exact §3 order using the
existing middleware constructors, and a `GatewayConfig` per `config['gateways']` entry. A
`'fake'` driver is pre-registered against `FakeGateway`.

**Decision — lazy resolution + memoization, not eager construction at `fromConfig()` time.**
`extend()` is only meaningful as an instance method called on the object `fromConfig()` already
returned (per PLAN.md's literal example). Building every configured `Gateway` eagerly would make
a driver registered via `extend()` right afterward too late for any gateway resolved during
construction. `gateway($name)` resolves on first access and memoizes thereafter.

**Decision — `extend()`'s key is the driver dispatch key, not the gateway instance name.** One
custom factory can back many gateway names that all set `'driver' => 'my_custom_driver'`.

**Decision — unknown gateway name / unknown driver throw `\InvalidArgumentException`**, not a new
`PaymentException` subtype — a config/deployment error, not a runtime payment failure, matching
`FakeGateway::willFail()`'s existing convention for authoring mistakes.

**Decision — the circuit breaker's PSR-16 cache defaults to the idempotency store's driver/DSN**
when `config['stores']['cache']` is absent, rather than silently defaulting to in-memory. §5's
example config has no `'cache'` key; an in-memory default there would quietly violate §6.4's
"shared across workers" the moment someone configures Redis/PDO for idempotency but leaves
`cache` unset.

`src/Store/StoreFactory.php` holds the `driver => store` switch logic, kept out of
`PaymentManager` to keep that class focused on registry/resolution — the same separation
`CircuitBreaker` (pure state machine) vs. `CircuitBreakerMiddleware` (wiring) already models.

---

## 2. `withFallback()` — **(review fix) narrowed fallthrough rule**

`src/FallbackGateway.php` implements only `Gateway`'s core (`name/capabilities/charge/find`), not
every `Supports*` interface — §6.4's only use-case is falling through after a breaker trip.

```php
final class FallbackGateway implements Gateway
{
    public function __construct(private readonly PaymentManager $manager, /** @var list<string> */ private readonly array $names) {}

    public function charge(PaymentRequest $request): Payment { return $this->attempt(static fn(Gateway $g) => $g->charge($request)); }
    public function find(string $providerReference): Payment { return $this->attempt(static fn(Gateway $g) => $g->find($providerReference)); }

    private function attempt(callable $op): Payment
    {
        $last = null;
        foreach ($this->names as $name) {
            try {
                return $op($this->manager->gateway($name));
            } catch (\Throwable $e) {
                if (!$this->fallsThrough($e)) {
                    throw $e;
                }
                $last = $e;
            }
        }
        throw $last;
    }

    private function fallsThrough(\Throwable $e): bool
    {
        return $e instanceof CircuitOpen
            || ($e instanceof NetworkException && !$e->ambiguous());
    }
}
```

**Decision — fall through only on `CircuitOpen` and a non-ambiguous `NetworkException`,** not on
every `RetryableException` as an earlier draft had it. The original, broader rule would have
fallen through on `RateLimitedException`, `ProviderUnavailable`, and an **ambiguous**
`NetworkException` (a timeout) too — all three mean the provider's edge/servers may have already
received and processed the request. Retrying those against a *second* provider risks charging the
same order twice through two different processors. `CircuitOpen` is safe because the breaker
never let the call leave this process; a non-ambiguous `NetworkException` is safe because it's
specifically the case where we know nothing reached the provider (e.g. connection refused before
a byte was sent) — see `NetworkException::ambiguous()`'s own docblock from Phase 2. Everything
else — every non-retryable failure too (`CardDeclined`, `InvalidRequest`) — rethrows immediately.

`capabilities()` delegates to the first name (documented simplification, not an intersection).
Idempotency keys are not coordinated across the chain — each wrapped gateway's own
`IdempotencyMiddleware` runs independently, keyed per its own gateway name.

`tests/Unit/FallbackGatewayTest.php` — one test per case: `CircuitOpen` on gateway 1 falls
through to gateway 2 and succeeds; a non-ambiguous `NetworkException` falls through; an
**ambiguous** `NetworkException` rethrows without touching gateway 2; `RateLimitedException`
rethrows; `ProviderUnavailable` rethrows; `CardDeclined` rethrows; every configured name
exhausted with only fallthrough-eligible errors rethrows the last one.

---

## 3a. `Contracts\EventStore`: atomic `claim()`/`release()` — contract change, approved

Plain `has()`-then-`remember()` has a duplicate-processing gap: two concurrent deliveries of the
same webhook event can both observe `has() === false` and both proceed to process it. Added,
additively (nothing in `src/` consumed `EventStore` before this phase, so nothing breaks):

```php
interface EventStore
{
    public function has(string $eventKey): bool;
    public function remember(string $eventKey, int $ttlSeconds): void;

    /**
     * Atomically claims $eventKey for $ttlSeconds if nothing currently holds it. Returns true
     * if this call won the claim (caller must process the event), false if it's already
     * claimed (caller must treat it as a duplicate and skip processing).
     *
     * Intended to be claimed first with a short "processing" TTL, then — only once
     * processing actually succeeds — extended via remember() to the long-term dedup TTL. That
     * way a worker that crashes mid-processing (never reaching remember() or release()) only
     * blocks a real redelivery for the short claim TTL, not the full dedup window.
     */
    public function claim(string $eventKey, int $ttlSeconds): bool;

    /**
     * Releases a claim early (e.g. the caller's own processing failed) so a future redelivery
     * of the same event can be claimed and retried instead of being treated as a duplicate.
     */
    public function release(string $eventKey): void;
}
```

**Implementations (claim/release added to every `EventStore`, sharing the same underlying
key/row `has()`/`remember()` already use — `remember()` after a `claim()` extends the same
entry's TTL rather than writing a second, independent one):**
- `InMemoryEventStore` (Phase 2, modified): one internal `expiresAt` map backs all four methods.
  `claim()` checks the map entry's expiry exactly like `has()` does; `remember()` overwrites the
  same key's expiry.
- `Store\Cache\CacheEventStore` (new, §5): `claim()` is a `has()`-then-`set()` check —
  **documented as non-atomic**, the same PSR-16 ceiling as `CacheLockStore` (no compare-and-set
  primitive in the interface). `release()` is `cache->delete()`.
- `Store\Pdo\PdoEventStore` (new, §6): `claim()` is a real atomic `INSERT` into
  `paybridge_webhook_events` (wins via the `PRIMARY KEY`); a unique-violation `PDOException`
  falls back to a conditional `UPDATE ... WHERE event_key = ? AND expires_at <= ?` (steals an
  expired claim, same idiom as `PdoLockStore`). `remember()` is an `UPDATE` extending the same
  row's `expires_at` (falls back to `INSERT` if the row doesn't exist — i.e. `remember()` called
  without a prior `claim()`, which `has()`/`remember()`'s original contract still allows).
  `release()` is `DELETE WHERE event_key = ?`.
- `Store\Redis\RedisEventStore` (new, §7): `claim()` is `SET key 1 NX EX ttl` (atomic).
  `remember()` is a plain `SET key 1 EX ttl` (overwrites/extends in one atomic command).
  `release()` is `DEL key`.

---

## 3. Webhooks: `WebhookHandler`, `WebhookRequest`, `PaymentStatusLookup`

### `Contracts/PaymentStatusLookup.php`

```php
interface PaymentStatusLookup
{
    public function findStatus(string $gateway, string $providerReference): ?PaymentStatus;
}
```

An app-supplied, optional dependency letting both `WebhookHandler` and `Reconciler` ask "what
does the app currently think this payment's status is", without PayBridge needing a payment
ledger of its own (confirmed non-goal territory — no `PaymentRepository` exists anywhere in this
library's directory layout or phase history). Returns `null` when the app doesn't know the
reference yet.

### `Webhook/WebhookRequest.php` (readonly: `payload`, `headers`)

### `Webhook/WebhookHandler.php` — **(review fix) short claim TTL, extended only on success**

```php
final class WebhookHandler
{
    public function __construct(
        private readonly \Closure $resolveGateway,       // callable(string): Gateway
        private readonly EventStore $events,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?PaymentStatusLookup $statusLookup = null,
        private readonly int $claimTtlSeconds = 300,      // processing-claim TTL (crash recovery window)
        private readonly int $dedupTtlSeconds = 604_800,  // §6.5: final dedup TTL once processed
    ) {}

    public function handle(string $gateway, WebhookRequest $request): PaymentEvent
    {
        $driver = ($this->resolveGateway)($gateway);
        $this->assertSupportsWebhooks($driver, $gateway);

        if (!$driver->verifySignature($request->payload, $request->headers)) {
            throw new InvalidSignature(...);
        }

        $event = $driver->parseEvent($request->payload, $request->headers);
        $dedupKey = sprintf('%s:%s', $gateway, $event->eventId);

        if (!$this->events->claim($dedupKey, $this->claimTtlSeconds)) {
            $this->dispatcher?->dispatch(new WebhookReceived($event, duplicate: true));
            return $event;
        }

        try {
            $this->applyStatusLookup($gateway, $event);
            $this->events->remember($dedupKey, $this->dedupTtlSeconds);
            $this->dispatcher?->dispatch(new WebhookReceived($event, duplicate: false));
        } catch (\Throwable $e) {
            $this->events->release($dedupKey);
            throw $e;
        }

        return $event;
    }
}
```

**Decision (review fix) — claim short, extend long, only on success.** An earlier draft claimed
directly at the full 7-day `dedupTtlSeconds` and relied on `release()` in the `catch` block for
cleanup. That leaves a real gap: a worker that crashes (killed, OOM, segfault) between `claim()`
and the `catch` block never runs `release()` either, so the event would stay claimed — and every
redelivery treated as an already-handled duplicate — for the **entire 7-day window**, even though
nothing was ever actually processed. Claiming with a short `claimTtlSeconds` (default 300s) and
only extending to the long `dedupTtlSeconds` via `remember()` *after* `applyStatusLookup()`
genuinely succeeds means a crash in that window self-heals: the claim simply expires after 5
minutes, and the provider's own retry (providers redeliver on a non-2xx or on timeout, and will
also naturally retry again later) gets a fresh, successful claim.

**Duplicate redelivery:** `claim()` returning `false` means either "already fully processed"
(claim was extended to 7 days via `remember()`) or "currently being processed elsewhere / crashed
within the last `claimTtlSeconds`" (claim still short-lived) — both cases are correctly treated as
"don't reprocess, just ack" by the caller. `handle()` never throws for a duplicate and always
returns the parsed `PaymentEvent`, so the controller can always return 200 on redelivery.

**Processing failure:** `release()` is only called when something inside the `try` throws (e.g. a
PSR-14 listener itself throws) — that rethrows out of `handle()`, so the webhook controller
returns 5xx and the provider redelivers; the next `claim()` succeeds immediately since the claim
was explicitly released, not left to expire.

`applyStatusLookup()`: if `$statusLookup` is configured and the event carries both a
`paymentReference` and a `status`: `findStatus()` returns `null` → nothing to diff, skip silently
(same as not configuring a lookup at all). Returns a status and
`StateMachine::canTransition($known, $event->status)` is `true` → dispatch
`PaymentStatusChanged(gateway, providerReference, previousStatus: $known, newStatus:
$event->status, payment: null)`. Returns a status and the transition is **not** legal
(stale/out-of-order) → dispatch `WebhookIgnoredStale($event, $known)` instead — the event is
still claimed/remembered as processed, so the provider still gets a 200 and won't keep
redelivering a genuinely stale event.

`verifySignature`/`parseEvent` reach the driver through the exact same `PipelineGateway` the
manager's registry already returns — those two methods already bypass the middleware pipeline
entirely (Phase 3), matching §3's "webhooks are a separate flow" diagram.

**5-minute replay tolerance (§6.6)** is each driver's own `verifySignature()` concern, not
`WebhookHandler`'s — real schemes (Stripe's `t=...,v1=...`) embed the timestamp inside the
signature material itself; `WebhookHandler` only ever sees `(payload, headers)` with no
protocol-agnostic way to locate a timestamp. `FakeGateway`'s fixture format is unchanged.

**New events** — `src/Event/PaymentStatusChanged.php` (`gateway`, `providerReference`,
`previousStatus: ?PaymentStatus`, `newStatus: PaymentStatus`, `payment: ?Payment` — nullable
since `WebhookHandler` never calls `find()`; `Reconciler` always does, §4),
`src/Event/WebhookIgnoredStale.php` (`event: PaymentEvent`, `knownStatus: PaymentStatus`),
`src/Event/WebhookReceived.php` (`event: PaymentEvent`, `duplicate: bool`). Only these three
PSR-14 events are introduced this phase — the other five named in §10
(`PaymentAttempted/Succeeded/Failed/Retried`, `CircuitOpened`) still depend on real
`Logging`/`MetricsMiddleware` wiring (needs `Http/Redactor`, not in Phase 4's own
directory-layout bullet) — deferred, not stubbed speculatively.

`tests/Unit/Webhook/WebhookHandlerTest.php` covers: valid/tampered signature; first delivery
claims + processes + `remember()`s + dispatches `WebhookReceived(duplicate: false)`; a second
delivery of the same event id is claimed=false → `WebhookReceived(duplicate: true)`, no
reprocessing; a processing failure calls `release()` and rethrows, and an immediate redelivery
after that release is claimed again (not a false duplicate); **the crash-recovery case**: claim
the dedup key directly (simulating a worker that claimed and then died with no `release()` call),
advance a `FakeClock`-backed store past `claimTtlSeconds` (default 300s) without ever calling
`remember()` or `release()`, then call `handle()` for the same event and assert it is claimed
again and processed (`duplicate: false`) — proving the 5-minute self-heal; and the
`PaymentStatusLookup` branches (no lookup, lookup returns null, valid transition, stale
transition).

---

## 4. `Reconciler` (`src/Reconciliation/`)

PayBridge has no payment ledger, so `Reconciler` never queries anything itself — the app supplies
an already-filtered iterable of candidates from its own storage.

```php
final readonly class ReconciliationCandidate
{
    public function __construct(public string $providerReference, public \DateTimeImmutable $pendingSince) {}
}

final class Reconciler
{
    public function __construct(
        private readonly \Closure $resolveGateway,
        private readonly Clock $clock,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?PaymentStatusLookup $statusLookup = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @return list<Payment> payments the caller should persist itself */
    public function reconcile(string $gateway, iterable $candidates, ?int $olderThanMinutes = null): array
    { /* see below */ }
}
```

Per candidate: optional age guard (defensive; the caller's own query is the primary "older than N
minutes" filter) → `$statusLookup?->findStatus($gateway, $ref)` for the previous status (`null`
if no lookup configured, or genuinely unknown — the DoD's own "unknown" wording) →
`$driver->find($ref)` (one exception here is logged and skipped, not fatal — a batch cron job
shouldn't abort over one bad reference) → if `$known === null` or `$known !== $fresh->status`:
skip if `$known !== null` and `StateMachine::canTransition($known, $fresh->status)` is `false`
(don't report an illegal regression); otherwise dispatch `PaymentStatusChanged(gateway, ref,
$known, $fresh->status, payment: $fresh)` and add `$fresh` to the returned list.

`Reconciler` only ever calls `find()` (a candidate's `providerReference` is only meaningful
against the one provider that produced it) and never persists anything itself — matches "PayBridge
has no storage of its own for payments."

---

## 5. PSR-16 cache-adapter stores (`src/Store/Cache/`)

`CacheIdempotencyStore`, `CacheEventStore`: thin wrappers over an app-supplied
`Psr\SimpleCache\CacheInterface`, using its own TTL support directly (`set($key, $value, $ttl)`).

`CacheLockStore::acquire()` is a `has()`-then-`set()` check — **not atomic**: PSR-16 exposes no
compare-and-set/add-if-absent primitive, so two concurrent callers can both observe
`has() === false` and both `set()`. Docblock, strengthened per review:

> **Dev / single-process only.** `acquire()` is a `has()`-then-`set()` check, not atomic — an
> accepted ceiling of PSR-16 itself (no CAS/NX primitive exists in the interface), not a bug
> specific to this class. Do not use as a production multi-worker lock: use the PDO store
> (atomic via a `UNIQUE` constraint) or the Redis store (atomic via `SET ... NX`) instead. The
> Laravel and Symfony bridges (Phase 5, see `docs/PLAN.md` §12) must use their own framework's
> lock primitive (`Cache::lock()` / `symfony/lock`) by default — never this class.

Config shape: `['driver' => 'cache', 'cache' => $psr16Instance, 'prefix' => '...']` — no DSN,
since there's no universal connection-string convention for an arbitrary PSR-16 implementation.

---

## 6. PDO stores (`src/Store/Pdo/` + `migrations/`) — + `purgeExpired()`/`Pruner`

Three tables:

```sql
CREATE TABLE paybridge_idempotency (
    id ..., gateway VARCHAR(191) NOT NULL, idempotency_key VARCHAR(191) NOT NULL,
    fingerprint CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL,
    response TEXT NULL, error TEXT NULL, expires_at ... NOT NULL,
    UNIQUE (gateway, idempotency_key)
);
CREATE INDEX ... (expires_at);

CREATE TABLE paybridge_locks (lock_key VARCHAR(191) PK, token CHAR(32) NOT NULL, expires_at ... NOT NULL);
CREATE INDEX ... (expires_at);

CREATE TABLE paybridge_webhook_events (event_key VARCHAR(191) PK, expires_at ... NOT NULL);
CREATE INDEX ... (expires_at);
```

**Decisions:** column named `idempotency_key`, not `key` (reserved word in MySQL — the DoD's
"unique (gateway, key) constraint" describes the logical pair, not a literal column name). Three
separate narrow tables rather than one polymorphic table. `response`/`error` are `TEXT` (portable
across SQLite/Postgres/MySQL; SQLite has no native JSON type and the library never queries inside
the JSON from SQL). One migration directory per engine
(`migrations/{sqlite,pgsql,mysql}/0001_create_tables.sql`) since PK/auto-increment syntax differs;
`Migrator::migrate(PDO $pdo)` detects the driver via `PDO::ATTR_DRIVER_NAME` and applies that
engine's files in order.

`PdoLockStore::acquire()` is genuinely atomic: a plain `INSERT` wins via the `PRIMARY KEY` on a
fresh key; a unique-violation `PDOException` falls back to a conditional `UPDATE ... WHERE
lock_key = ? AND expires_at <= ?`, checking `rowCount() === 1` to know whether this caller won
the steal-an-expired-lock race. `PdoIdempotencyStore::put()`/`PdoEventStore::claim()`/`remember()`
use the same idiom. All `expires_at` comparisons bind a parameter from the injected `Clock` (never
SQL `NOW()`), so these stores stay testable with `FakeClock`.

**Composite-key parsing:** `IdempotencyMiddleware` calls `IdempotencyStore::get/put` with one
string, `"idem:{gateway}:{key}"`. `PdoIdempotencyStore` strips the `idem:` prefix and splits on
the first remaining `:` to populate the two-column constraint the DoD mandates — accepted rather
than changing the Phase 1 `IdempotencyStore` interface shape.

**`purgeExpired(): int`**, added to all three PDO stores: `DELETE FROM <table> WHERE expires_at
<= ?` (bound from `Clock`), returns `$stmt->rowCount()`. New `src/Store/Pdo/Pruner.php`:

```php
final class Pruner
{
    public function __construct(
        private readonly PdoIdempotencyStore $idempotency,
        private readonly PdoLockStore $locks,
        private readonly PdoEventStore $events,
    ) {}

    /** @return array{idempotency: int, locks: int, events: int} */
    public function pruneAll(): array
    {
        return [
            'idempotency' => $this->idempotency->purgeExpired(),
            'locks' => $this->locks->purgeExpired(),
            'events' => $this->events->purgeExpired(),
        ];
    }
}
```

Reads already filter out expired rows (`WHERE expires_at > ?`), so correctness never depended on
deletion — `Pruner` is a housekeeping job, meant to run from cron like `Reconciler`: it owns no
scheduler itself, a cron entry just invokes `pruneAll()` periodically (a console command wrapper
is Phase 5 bridge territory). Not added to the PSR-16 cache stores (§5) or Redis (§7): PSR-16's
own TTL and Redis's own `EX` already expire keys without any manual sweep; only PDO rows are "dead
but still present" and need explicit pruning. `tests/Unit/Store/Pdo/PrunerTest.php` uses
`FakeClock`: insert/claim/put records with short TTLs, advance the fake clock past expiry, call
`pruneAll()`, assert the exact per-table counts and that still-valid rows survive.

`ext-pdo` (+ `pdo_sqlite`/`pdo_pgsql`/`pdo_mysql`) go in composer's `suggest`, not `require`.

---

## 7. Redis stores (`src/Store/Redis/`)

Depend on ext-redis's built-in `\Redis` class directly — not `predis/predis` — matching this
codebase's "zero new Composer dependency until something actually needs it" rule (Phase 0/1) and
keeping `predis` from becoming this library's first third-party (non-PSR) dependency.
`"ext-redis": "*"` goes in `suggest`.

`RedisLockStore::acquire()`: `SET key token NX PX ttlMs`. `release()`: a compare-and-delete Lua
script (plain `DEL` would delete a lock someone else now legitimately owns after ours expired —
the same race `InMemoryLockStore`/`PdoLockStore` already close via their own token checks).
`RedisIdempotencyStore`: `SET ... EX` / `GET`. `RedisEventStore`: `has()`/`remember()` plus
`claim()` (`SET key 1 NX EX ttl`) and `release()` (`DEL key`), per §3a.
`RedisConnectionFactory::fromDsn('redis://host:port'): \Redis` parses the DSN format from §5's
PLAN.md example.

---

## 8. `examples/plain-php`

One parameterized script (`PAYBRIDGE_STORE_DRIVER=pdo|redis`, default `pdo` against a local
SQLite file) rather than two divergent demos — proves the store backends are interchangeable
through the same `fromConfig()` call. `run.php` walks through, against one `PaymentManager`
instance: charge → repeat the identical charge call with the same idempotency key (proves no
second provider-side charge happens, observable via `FakeGateway`'s per-reference state) →
partial refund → a webhook carrying a status update, fed through `webhookHandler()->handle()`
**twice with the same event id** (`duplicate: no` then `duplicate: yes`) → a second webhook with a
different event id but a deliberately stale status (`WebhookIgnoredStale`) → a
`ReconciliationCandidate` for a payment left in `REQUIRES_ACTION`, reconciled once (no change) and
again after it advances (`PaymentStatusChanged` before/after) → (PDO path only)
`Pruner::pruneAll()` after manually expiring one record, printing the purge counts. A minimal
in-memory `PaymentStatusLookup` implementation inside the script drives the webhook/reconcile
status checks. If the SQLite file doesn't exist yet, `Migrator::migrate()` runs first.

---

## Composer dependency delta

```jsonc
"require": {
    "php": "^8.3",
    "psr/clock": "^1.0",
    "psr/simple-cache": "^3.0",
    "psr/log": "^3.0",
    "psr/event-dispatcher": "^1.0",
    "psr/http-client": "^1.0"
},
"suggest": {
    "ext-pdo": "Required for the PDO-backed stores (Store/Pdo/*).",
    "ext-pdo_sqlite": "Required if using the PDO store with a sqlite: DSN.",
    "ext-pdo_pgsql": "Required if using the PDO store with a pgsql: DSN.",
    "ext-pdo_mysql": "Required if using the PDO store with a mysql: DSN.",
    "ext-redis": "Required for the Redis-backed stores (Store/Redis/*)."
}
```

`phpunit.xml.dist` gains an `integration` testsuite (`tests/Integration`) for the
Postgres/MySQL/Redis tests, which skip (not fail) without a reachable server — same convention as
§11.7's sandbox tests skipping without credentials.

---

## Files added

```
src/PaymentManager.php
src/FallbackGateway.php
src/Config/GatewayConfig.php
src/Config/ResilienceConfig.php
src/Contracts/PaymentStatusLookup.php
src/Webhook/WebhookHandler.php
src/Webhook/WebhookRequest.php
src/Event/WebhookReceived.php
src/Event/WebhookIgnoredStale.php
src/Event/PaymentStatusChanged.php
src/Reconciliation/Reconciler.php
src/Reconciliation/ReconciliationCandidate.php
src/Store/StoreFactory.php
src/Store/Cache/CacheIdempotencyStore.php
src/Store/Cache/CacheLockStore.php
src/Store/Cache/CacheEventStore.php
src/Store/Pdo/PdoIdempotencyStore.php
src/Store/Pdo/PdoLockStore.php
src/Store/Pdo/PdoEventStore.php
src/Store/Pdo/Migrator.php
src/Store/Pdo/Pruner.php
src/Store/Pdo/migrations/sqlite/0001_create_tables.sql
src/Store/Pdo/migrations/pgsql/0001_create_tables.sql
src/Store/Pdo/migrations/mysql/0001_create_tables.sql
src/Store/Redis/RedisIdempotencyStore.php
src/Store/Redis/RedisLockStore.php
src/Store/Redis/RedisEventStore.php
src/Store/Redis/RedisConnectionFactory.php
examples/plain-php/composer.json
examples/plain-php/bootstrap.php
examples/plain-php/run.php
examples/plain-php/README.md
tests/Unit/PaymentManagerTest.php
tests/Unit/FallbackGatewayTest.php
tests/Unit/Config/GatewayConfigTest.php
tests/Unit/Config/ResilienceConfigTest.php
tests/Unit/Webhook/WebhookHandlerTest.php
tests/Unit/Reconciliation/ReconcilerTest.php
tests/Unit/Store/Cache/{CacheIdempotencyStoreTest,CacheLockStoreTest,CacheEventStoreTest}.php
tests/Unit/Store/Pdo/{PdoIdempotencyStoreTest,PdoLockStoreTest,PdoEventStoreTest,PrunerTest}.php
tests/Integration/Store/Pdo/{PgsqlStoreTest,MysqlStoreTest}.php
tests/Integration/Store/Redis/RedisStoreTest.php
```

## Files modified

```
src/Contracts/EventStore.php                         + claim()/release()
src/Store/InMemory/InMemoryEventStore.php             + claim()/release()
tests/Unit/Store/InMemory/InMemoryEventStoreTest.php  + claim()/release() coverage
composer.json                                         new require + suggest
phpunit.xml.dist                                      new `integration` testsuite
docs/PLAN.md                                          §12 Phase 5 lock-store requirement
```

No changes to `src/Middleware/*`, `src/Resilience/*`, `src/Status/*`, `src/Domain/*`,
`src/Exception/*`, `src/Drivers/Fake/FakeGateway.php`, or `IdempotencyStore`/`LockStore`.

## Verification

- `composer test`, `composer stan` (level 9), `composer cs` all pass, per `CLAUDE.md`.
- `examples/plain-php/run.php` (SQLite, default) and `PAYBRIDGE_STORE_DRIVER=redis
  examples/plain-php/run.php` (needs a local `redis-server`) both complete the full walkthrough —
  the literal Phase 4 DoD.
- `FallbackGatewayTest` exercises every fallthrough/rethrow case individually.
- `WebhookHandlerTest` exercises claim → process → remember, duplicate-after-success,
  release-on-failure + re-claim, and the crash-recovery (claim-expires-after-300s) sequence.
- `PrunerTest` proves `purgeExpired()` against a `FakeClock`-controlled expiry boundary on all
  three PDO tables.
- Update `docs/PROGRESS.md` with the decisions above once implemented.
