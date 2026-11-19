# PayBridge — Universal PHP Payment Gateway Library

> **For Claude Code:** This README is the build spec. Work through the phases in order. Each phase has tasks and a Definition of Done (DoD). Don't start a phase until the previous phase's DoD passes. If something here is ambiguous, choose the simplest option that keeps the public contracts stable, and leave a `// DECISION:` comment that explains the choice.

---

## 1. Goal

A framework-agnostic PHP library that puts one stable API in front of any payment provider (Stripe, PayPal, banks / Open Banking, local PSPs, manual bank transfer). It must:

- **Drop into an existing project fast**: one Composer package, one config array, one facade/service.
- **Be extendable**: a new gateway is one driver class plus a status map. Gateways with an unfamiliar or simple REST API can be added through **config only** (the declarative HTTP driver).
- **Be resilient by default**: idempotency, retries with exponential backoff + jitter, timeouts, circuit breaker, TTL-based caching and locks, plus webhook deduplication.
- **Be safe**: never store card data (PAN/CVV), redact secrets in logs, and verify every webhook signature.

Non-goals (v1): building a card vault, PCI scope, subscriptions/billing engine, payouts to many recipients (can come later through capabilities).

---

## 2. Tech Constraints

| Item | Choice |
|---|---|
| PHP | 8.3+ (readonly classes, enums, `never`, DNF types) |
| HTTP | PSR-18 client + PSR-17 factories (Guzzle/Symfony HttpClient work through discovery) |
| Logging | PSR-3 |
| Cache / storage | PSR-16 for simple TTL cache; own `IdempotencyStore` + `LockStore` interfaces with Redis, PDO (PostgreSQL/MySQL) and in-memory implementations |
| Events | PSR-14 |
| Clock | PSR-20 (`ClockInterface`) so TTL and backoff are testable |
| Tests | PHPUnit 11, Pest optional; PHPStan level 9; PHP-CS-Fixer (PER-CS 2.0) |
| Framework bridges | `paybridge/symfony-bundle` and `paybridge/laravel`, in the same monorepo, part of v1 (see §11) |

Core package has **zero framework dependencies**. Gateway SDKs (e.g. `stripe/stripe-php`) are **not** required. Drivers talk to HTTP APIs directly through PSR-18, so the core never pulls in a vendor SDK and its version conflicts.

---

## 3. Architecture Overview

```
Your app
   │
   ▼
PaymentManager  ──(resolves by name)──►  Gateway (driver)
   │                                      ▲
   ▼                                      │
Middleware pipeline (outer → inner):      │
  Logging → Metrics → Idempotency → CircuitBreaker → Retry → Timeout → Driver
                                                             │
                                                     Transport (PSR-18)
                                                             │
                                                     Provider API

Webhooks:
HTTP request → WebhookHandler → Driver::verifySignature → Driver::parseEvent
             → Dedup (EventStore, TTL) → normalized PaymentEvent → PSR-14 dispatch
```

### Key ideas

1. **Normalized domain model.** The app only sees `Payment`, `Refund`, `PaymentStatus`, `PaymentEvent`, `Money`. Provider-specific data lives in `$raw` and `$providerReference`.
2. **Capabilities, not assumptions.** Each driver declares what it supports (`AUTHORIZE`, `CAPTURE`, `REFUND_PARTIAL`, `VOID`, `REDIRECT_FLOW`, `WEBHOOKS`, `3DS`, …). Calling an unsupported operation throws `UnsupportedOperation` before any network call.
3. **Middleware for cross-cutting concerns.** Retries, idempotency and similar logic are written once and wrap every driver. Driver authors never write retry loops.
4. **Status state machine.** Every provider status maps to one internal `PaymentStatus`. Illegal transitions are rejected and logged.

---

## 4. Directory Layout

```
src/
  PaymentManager.php
  Config/
    GatewayConfig.php
    ResilienceConfig.php
  Contracts/
    Gateway.php                 # core driver interface
    SupportsAuthorize.php       # optional capability interfaces
    SupportsCapture.php
    SupportsRefund.php
    SupportsVoid.php
    SupportsWebhooks.php
    SupportsRedirect.php
    IdempotencyStore.php
    LockStore.php
    EventStore.php
    Transport.php
  Domain/
    Money.php                   # amount in minor units + Currency
    Currency.php                # ISO 4217 code + exponent
    PaymentRequest.php
    CaptureRequest.php
    RefundRequest.php
    Payment.php                 # normalized result
    Refund.php
    PaymentStatus.php           # enum
    PaymentEvent.php            # normalized webhook event
    NextAction.php              # redirect / 3DS / bank-auth instructions
    Customer.php
    Capability.php              # enum
  Status/
    StatusMap.php               # provider status → PaymentStatus
    StateMachine.php
  Middleware/
    Middleware.php              # interface
    Pipeline.php
    LoggingMiddleware.php
    MetricsMiddleware.php
    IdempotencyMiddleware.php
    CircuitBreakerMiddleware.php
    RetryMiddleware.php
    TimeoutMiddleware.php
  Resilience/
    RetryPolicy.php
    Backoff/
      ExponentialJitterBackoff.php
      ConstantBackoff.php
    CircuitBreaker.php
    RequestFingerprint.php
  Http/
    Psr18Transport.php
    HttpRequestBuilder.php
    Redactor.php
  Webhook/
    WebhookHandler.php
    WebhookRequest.php
  Store/
    InMemory/ …
    Redis/ …
    Pdo/ …                      # + migrations/*.sql
  Exception/
    PaymentException.php        # base
    RetryableException.php      # marker interface
    NetworkException.php        # retryable
    RateLimitedException.php    # retryable, carries Retry-After
    ProviderUnavailable.php     # retryable (5xx)
    CardDeclined.php            # not retryable
    InvalidRequest.php          # not retryable (4xx validation)
    AuthenticationFailed.php    # not retryable (bad credentials)
    IdempotencyConflict.php     # same key, different payload
    UnsupportedOperation.php
    InvalidSignature.php
    CircuitOpen.php
  Drivers/
    AbstractHttpGateway.php     # shared helpers for driver authors
    Stripe/
      StripeGateway.php
      StripeStatusMap.php
      StripeErrorMapper.php
    PayPal/
      PayPalGateway.php
      PayPalTokenProvider.php   # OAuth2 client-credentials, token cached with TTL
      PayPalStatusMap.php
      PayPalErrorMapper.php
    Declarative/
      DeclarativeGateway.php    # config-driven driver for unknown REST APIs
      MappingSchema.php
    BankTransfer/
      BankTransferGateway.php   # manual/offline: generates reference, confirmed via webhook/admin
    Fake/
      FakeGateway.php           # deterministic test driver with scripted failures
tests/
  Contract/
    GatewayContractTestCase.php # every driver must extend and pass this
  Unit/
  Integration/                  # sandbox tests, skipped without env credentials
docs/
  writing-a-driver.md
  declarative-driver.md
  resilience.md
examples/
  plain-php/
bridges/
  laravel/                      # paybridge/laravel (separate package, same monorepo)
  symfony/                      # paybridge/symfony-bundle
bin/
  sandbox-app                   # creates a fresh Laravel/Symfony app wired to local packages
docker-compose.sandbox.yml
.env.sandbox.example
```

---

## 5. Public API (what integrators use)

```php
use PayBridge\PaymentManager;
use PayBridge\Domain\{Money, PaymentRequest, RefundRequest};

$payments = PaymentManager::fromConfig([
    'default' => 'stripe',
    'stores' => [
        'idempotency' => ['driver' => 'redis', 'dsn' => 'redis://localhost:6379', 'ttl' => 86400],
        'lock'        => ['driver' => 'redis', 'ttl' => 30],
        'events'      => ['driver' => 'pdo', 'dsn' => 'pgsql:host=db;dbname=app', 'ttl' => 604800],
    ],
    'resilience' => [
        'timeout_ms'      => 10000,
        'retry'           => ['max_attempts' => 4, 'base_ms' => 200, 'max_ms' => 5000, 'jitter' => 'full'],
        'circuit_breaker' => ['failure_threshold' => 5, 'cooldown_s' => 30],
    ],
    'gateways' => [
        'stripe' => ['driver' => 'stripe', 'secret_key' => getenv('STRIPE_SECRET'), 'webhook_secret' => getenv('STRIPE_WHSEC')],
        'paypal' => ['driver' => 'paypal', 'client_id' => '...', 'client_secret' => '...', 'mode' => 'sandbox'],
        'tbc'    => ['driver' => 'declarative', 'schema' => __DIR__.'/gateways/tbc.php'],
    ],
], logger: $logger, httpClient: $psr18, events: $dispatcher);

$payment = $payments->gateway('stripe')->charge(new PaymentRequest(
    amount: Money::of('49.99', 'EUR'),
    idempotencyKey: 'order-1234-charge',   // required, see §6.1
    orderId: 'order-1234',
    description: 'Order #1234',
    returnUrl: 'https://shop.test/return',
    metadata: ['customer_id' => '42'],
));

if ($payment->nextAction) {
    // redirect / 3DS / bank approval
    return redirect($payment->nextAction->url);
}

$payments->gateway('stripe')->refund(new RefundRequest(
    paymentReference: $payment->providerReference,
    amount: Money::of('10.00', 'EUR'),
    idempotencyKey: 'order-1234-refund-1',
));
```

Core interface (keep it small; optional abilities go in `Supports*` interfaces):

```php
interface Gateway
{
    public function name(): string;
    /** @return list<Capability> */
    public function capabilities(): array;
    public function charge(PaymentRequest $request): Payment;      // authorize+capture, or start redirect flow
    public function find(string $providerReference): Payment;      // fetch current state (used for reconciliation)
}
```

`PaymentStatus` enum: `PENDING`, `REQUIRES_ACTION`, `AUTHORIZED`, `CAPTURED`, `PARTIALLY_REFUNDED`, `REFUNDED`, `VOIDED`, `FAILED`, `EXPIRED`.

---

## 6. Resilience Spec (must be built exactly like this)

### 6.1 Idempotency

- `idempotencyKey` is **required** on every mutating request. Throw `InvalidRequest` if it's missing. Integrators should derive it from their own domain, e.g. `order-{id}-charge`.
- The middleware computes `fingerprint = sha256(canonical_json(request without idempotencyKey))`.
- Flow:
  1. `lock.acquire("idem:{gateway}:{key}", ttl)`. If the lock is held, wait or poll up to the timeout, then throw `IdempotencyConflict('in_progress')`.
  2. Look up the stored record:
     - **Found, same fingerprint, status completed** → return the stored `Payment` (no network call).
     - **Found, different fingerprint** → throw `IdempotencyConflict('payload_mismatch')`.
     - **Found, status failed with a non-retryable error** → return or rethrow the stored error.
     - **Not found** → call inner, store `{fingerprint, status, response|error, created_at}` with TTL (default 24h), then release the lock.
- **Also forward the key to the provider** when it supports one (Stripe `Idempotency-Key` header, PayPal `PayPal-Request-Id`). The same key must be reused across retries. That way, if our store is lost, the provider still deduplicates.
- Keys are namespaced per gateway.

### 6.2 Retries + Backoff

- Retry **only** exceptions implementing `RetryableException`: network errors, timeouts, HTTP 408/425/429/500/502/503/504.
- **Never** retry 4xx validation errors, declines, or auth failures.
- Backoff is exponential with **full jitter**: `sleep = random(0, min(max_ms, base_ms * 2^attempt))`.
- Honor `Retry-After` from `RateLimitedException` (capped at `max_ms`).
- Mutating calls are retried **only because** the idempotency key goes to the provider. If a driver can't forward a key (`Capability::PROVIDER_IDEMPOTENCY` is missing), it must not retry mutating calls after a timeout. Instead, call `find()` or reconcile.
- Sleep goes through an injectable `Sleeper` interface so tests run instantly.

### 6.3 Timeouts

- Per-request connect/read timeout from config. A timeout becomes `NetworkException` (retryable).
- An **ambiguous outcome** (timeout after the request was sent) on a provider without idempotency → mark the payment `PENDING`, emit a `PaymentOutcomeUnknown` event, and let reconciliation resolve it.

### 6.4 Circuit Breaker

- One breaker per gateway, with its state in the cache store so it is shared across workers.
- States: closed → open after N consecutive retryable failures → half-open after the cooldown → closed after one success.
- While open, throw `CircuitOpen` immediately. The app can then fall back to another gateway (see `PaymentManager::withFallback(['stripe', 'paypal'])` in phase 5).

### 6.5 TTL Everywhere

| Data | Default TTL |
|---|---|
| Idempotency records | 24h |
| Locks | 30s |
| OAuth access tokens (PayPal, banks) | provider `expires_in` minus 60s safety margin |
| Webhook event IDs (dedup) | 7 days |
| Circuit breaker state | cooldown period |

### 6.6 Webhooks

- `WebhookHandler::handle(string $gateway, WebhookRequest $req): PaymentEvent`
- Steps: verify signature (constant-time compare, timestamp tolerance of 5 min) → parse → normalize → dedup by `gateway:event_id` in `EventStore` → state machine check → dispatch PSR-14 event.
- Webhooks can arrive out of order. The state machine ignores stale transitions (e.g. `CAPTURED` after `REFUNDED`).
- An invalid signature throws `InvalidSignature`. The app should then return HTTP 400.

---

## 7. Extensibility: Three Ways to Add a Gateway

### 7.1 Full driver (for complex APIs: Stripe, PayPal, Adyen)
Extend `AbstractHttpGateway` and implement `Gateway` plus the `Supports*` interfaces you need. You provide:
- `StatusMap` (provider status string → `PaymentStatus`)
- `ErrorMapper` (HTTP status + provider error code → library exception, including whether it's retryable)
- Request builders and response normalizers

The driver must pass `GatewayContractTestCase`.

### 7.2 Declarative driver (for "unknown" banks and simple REST PSPs)
No PHP class needed. You describe the API in a config file:

```php
// gateways/tbc.php
return [
    'base_url' => 'https://api.bank.example/v1',
    'auth' => ['type' => 'oauth2_client_credentials', 'token_url' => '/oauth/token', 'client_id' => '...', 'client_secret' => '...'],
    // or: ['type' => 'bearer', 'token' => '...'] | ['type' => 'basic', ...] | ['type' => 'hmac', 'header' => 'X-Signature', 'algo' => 'sha256', 'secret' => '...']
    'idempotency_header' => 'Idempotency-Key',   // null if unsupported
    'capabilities' => ['charge', 'refund', 'webhooks', 'redirect'],
    'operations' => [
        'charge' => [
            'method' => 'POST',
            'path' => '/payments',
            'body' => [                                  // dot-path template from PaymentRequest
                'amount'      => '{{amount.minor}}',
                'currency'    => '{{amount.currency}}',
                'order_id'    => '{{orderId}}',
                'callback'    => '{{returnUrl}}',
            ],
            'response' => [
                'reference'   => 'data.id',
                'status'      => 'data.state',
                'redirect_url'=> 'data.links.approve',
            ],
        ],
        'find'   => ['method' => 'GET',  'path' => '/payments/{{reference}}', 'response' => [/* … */]],
        'refund' => ['method' => 'POST', 'path' => '/payments/{{reference}}/refund', 'body' => [/* … */], 'response' => [/* … */]],
    ],
    'status_map' => ['CREATED' => 'pending', 'APPROVED' => 'authorized', 'COMPLETED' => 'captured', 'REJECTED' => 'failed'],
    'error_map'  => ['http' => [429 => 'rate_limited', 503 => 'unavailable'], 'codes' => ['INSUFFICIENT_FUNDS' => 'declined']],
    'webhook' => [
        'signature' => ['type' => 'hmac', 'header' => 'X-Signature', 'algo' => 'sha256', 'secret' => '...'],
        'event_id' => 'id', 'reference' => 'payment.id', 'status' => 'payment.state',
    ],
];
```

Keep the template engine tiny: `{{path}}` substitution and dot-path extraction only. No logic and no `eval`. When a bank needs more than the schema can express, add an optional `hooks` class (`beforeRequest`, `afterResponse`) or turn it into a full driver.

### 7.3 Decorating an existing driver
For "Stripe, but with our extra metadata", wrap the gateway in your own middleware or subclass the driver. The core stays untouched.

---

## 8. Money Rules
- Store and transmit **integer minor units** only. Never use floats.
- `Money::of('49.99', 'EUR')` parses a string; `Money::ofMinor(4999, 'EUR')` takes minor units.
- Currency exponent comes from ISO 4217 (JPY=0, EUR=2, KWD=3). Drivers convert if the provider expects a different format.
- Partial refunds are checked against `captured - refunded` before the call.

---

## 9. Security Checklist
- [ ] `Redactor` masks `secret`, `password`, `token`, `authorization`, `card`, `cvv`, `iban` (keeps the last 4) in all logs and exceptions.
- [ ] No raw card data ever passes through the library. Use provider-hosted fields, redirects or tokens.
- [ ] Webhook signatures are verified with `hash_equals`, with timestamp tolerance against replay.
- [ ] Every HTTP call goes over TLS; there is no option to disable verification outside a `sandbox` flag.
- [ ] Secrets come from config only, never from hardcoded defaults.

---

## 10. Observability
- PSR-3 log on every attempt: `gateway`, `operation`, `attempt`, `idempotency_key`, `duration_ms`, `http_status`, `outcome`. Payloads are redacted.
- PSR-14 events: `PaymentAttempted`, `PaymentSucceeded`, `PaymentFailed`, `PaymentRetried`, `CircuitOpened`, `WebhookReceived`, `PaymentOutcomeUnknown`, `PaymentStatusChanged`.
- An optional `MetricsCollector` interface (counters and histograms) with a no-op default.

---

## 11. Sandbox Testing in a Fresh Symfony / Laravel App (hard requirement)

A developer creates a **brand-new** Laravel or Symfony app, installs the package, adds sandbox keys to `.env`, and can run real test payments against Stripe, PayPal and every other provider that has a sandbox. They don't write any code.

### 11.1 Install flow (must work exactly like this)

**Laravel**
```bash
composer create-project laravel/laravel pb-test && cd pb-test
composer require paybridge/laravel --dev   # or a path repo while developing locally (see 11.5)
php artisan paybridge:install              # publishes config/paybridge.php, adds .env keys, registers demo routes
# put sandbox keys into .env
php artisan paybridge:doctor
php artisan paybridge:test stripe
```

**Symfony**
```bash
symfony new pb-test --webapp && cd pb-test   # or: composer create-project symfony/skeleton pb-test
composer require paybridge/symfony-bundle --dev   # Flex recipe creates config/packages/paybridge.yaml + .env keys
php bin/console paybridge:doctor
php bin/console paybridge:test stripe
```

Requirements:
- Laravel: package auto-discovery. Symfony: a Flex recipe (or the `paybridge:install` command when recipes aren't available). No manual service wiring.
- Supported versions: Laravel 11+ and Symfony 6.4 LTS / 7.x.
- Default stores work with **zero infrastructure**. They use the framework cache (Laravel `Cache`, Symfony `cache.app`) and the app's DB connection, so a fresh app with SQLite works. Redis is optional.
- A migration for the idempotency and webhook-event tables ships with both bridges (`php artisan migrate` / `doctrine:migrations:migrate`).

### 11.2 Console commands (both bridges, same names and behaviour)

| Command | What it does |
|---|---|
| `paybridge:doctor` | Checks the config for each gateway, whether its keys are present, **test vs live mode**, connectivity (an authenticated read-only call), the webhook secret, store reachability and whether migrations ran. Prints a green/red table. |
| `paybridge:test {gateway} [--amount=10.00] [--currency=EUR] [--scenario=success]` | Runs the full lifecycle against the sandbox: charge → (if a redirect is needed: prints the approval URL and waits/polls) → find → partial refund → full refund. Prints each step with its status, timing and provider reference. |
| `paybridge:test {gateway} --scenario=declined\|3ds\|insufficient_funds\|timeout` | Uses the provider's documented test inputs (e.g. Stripe test payment methods) to trigger each path. Scenarios the provider doesn't support are reported as "skipped", never faked. |
| `paybridge:test {gateway} --idempotency-check` | Sends the same request twice and asserts a single provider charge, then sends the same key with a different amount and asserts `IdempotencyConflict`. |
| `paybridge:test all` | Runs the default scenario for every configured gateway and prints a summary matrix. |
| `paybridge:webhook:replay {gateway} {eventId}` | Re-processes a stored event (handy when debugging dedup and out-of-order delivery). |

**Safety:** the test commands **refuse live credentials** (e.g. a `sk_live_` key, or PayPal `mode=live`) unless `--i-know-this-is-live` is passed, and they never run when `APP_ENV=prod`.

### 11.3 Demo UI (dev only)

Both bridges register `/_paybridge/demo` only when `APP_ENV` is `local`/`dev` and `paybridge.demo.enabled=true`:
- A plain page with a gateway dropdown, amount, currency and a "Pay" button.
- It handles redirect flows (PayPal approval, 3DS, bank authorization) with real return/cancel routes.
- It shows the payment's live status, refreshed from incoming webhooks, plus a log of received webhook events with signature valid/invalid and duplicate yes/no.
- The webhook endpoint is `/_paybridge/webhook/{gateway}` (also used in production; only the demo page is dev-only).

### 11.4 Local webhooks

- **Stripe:** document `stripe listen --forward-to localhost:8000/_paybridge/webhook/stripe` and put the printed `whsec_` into `.env`. `paybridge:doctor` detects a missing secret.
- **Others:** document using a tunnel (`cloudflared tunnel --url http://localhost:8000` or ngrok) and pasting the public URL into the provider's sandbox dashboard.
- `paybridge:doctor --webhook-url` prints the exact URL to register for each gateway.

### 11.5 Developing the library against a fresh app

Ship `bin/sandbox-app {laravel|symfony}`, which:
1. Creates a fresh app in `./sandbox/{framework}` (git-ignored).
2. Adds a Composer **path repository** that points at the local packages (symlinked, so edits show up instantly).
3. Requires the bridge, runs the install command and migrations, and copies `.env.sandbox.example`.
4. Prints the next steps (fill in keys → `doctor` → `test`).

Also ship `docker-compose.sandbox.yml` (PHP-FPM + nginx + optional Redis + `stripe-cli` container for webhook forwarding), so it runs the same on Windows, macOS and Linux.

### 11.6 Sandbox matrix (driver authors fill this in; verify against current provider docs)

| Provider | Sandbox | Credentials in `.env` | Test inputs | Webhooks locally |
|---|---|---|---|---|
| Stripe | Test mode | `STRIPE_SECRET=sk_test_…`, `STRIPE_WEBHOOK_SECRET=whsec_…` | Official test payment methods/cards | Stripe CLI |
| PayPal | developer.paypal.com sandbox | `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_MODE=sandbox` | Sandbox buyer accounts | Tunnel + webhook ID |
| BankTransfer | Local only | none | `markPaid()` via the demo UI | n/a |
| Declarative / banks | Per-bank sandbox if offered | per schema | per bank docs | Tunnel |
| Fake | Always available | none | scripted scenarios | in-process |

Each driver ships a `SandboxScenarios` class that maps `success`, `declined`, `3ds`, … to that provider's test inputs. `paybridge:test` reads from it.

### 11.7 Automated sandbox tests

- `tests/Sandbox/` (PHPUnit group `sandbox`) runs the contract scenarios against the real sandbox APIs. A test is **skipped** (not failed) when its credentials aren't set.
- `composer test:sandbox` runs that group.
- CI runs the Laravel and Symfony **fresh-app smoke test** on every PR: create the app → install → `paybridge:doctor` → `paybridge:test fake`. When repository secrets exist, it also runs `paybridge:test stripe` and `paybridge:test paypal`.

---

## 12. Build Phases (for Claude Code)

### Phase 0: Skeleton
- `composer.json` (PSR-4 `PayBridge\`), PHPUnit, PHPStan (level 9), CS fixer, GitHub Actions CI on PHP 8.3/8.4/8.5.
- **DoD:** `composer test`, `composer stan` and `composer cs` all pass on an empty skeleton.

### Phase 1: Domain + contracts
- `Money`, `Currency`, the request/response DTOs (readonly), `PaymentStatus`, `Capability`, `StateMachine`, the exception hierarchy, and all interfaces.
- **DoD:** unit tests for Money arithmetic/parsing (including JPY/KWD), state machine transitions (valid and invalid), 100% coverage on `Domain/` and `Status/`.

### Phase 2: Resilience core
- `Pipeline` plus all middlewares, `RetryPolicy`, `ExponentialJitterBackoff`, `CircuitBreaker`, `RequestFingerprint`, and the in-memory stores, using an injectable `Clock` and `Sleeper`.
- **DoD:** tests prove:
  - a retryable error is retried up to max with correct backoff bounds; a non-retryable error is not retried
  - `Retry-After` is honored
  - same key + same payload → a single inner call; same key + different payload → `IdempotencyConflict`
  - concurrent calls with the same key → one wins, the other gets the cached result or `in_progress`
  - the breaker opens, half-opens and closes correctly
  - TTL expiry works (by advancing a fake clock)

### Phase 3: FakeGateway + contract test suite
- `FakeGateway` with scriptable behaviour (`->willFail(NetworkException::class, times: 2)->thenSucceed()`).
- `GatewayContractTestCase`: charge, find, refund (full and partial), void, webhook verify/parse, error mapping, capability enforcement.
- **DoD:** FakeGateway passes the contract suite through the full middleware pipeline.

### Phase 4: Manager, webhooks, persistent stores
- `PaymentManager::fromConfig()`, gateway registry (`extend('name', fn($config) => new MyGateway(...))`), `withFallback()`.
- `WebhookHandler` with dedup.
- Stores: PSR-16 cache adapter (zero-infra default), PDO (SQLite/PostgreSQL/MySQL) and Redis, plus SQL migrations (idempotency table with a unique `(gateway, key)` constraint and an `expires_at` index).
- A `Reconciler` that finds `PENDING`/unknown payments older than N minutes and calls `find()` to sync their status (meant to run from cron).
- **DoD:** end-to-end example in `examples/plain-php` works with FakeGateway on SQLite and on Redis.

### Phase 5: Framework bridges + sandbox tooling (built before the real drivers, so every driver is testable in a real app from day one)
- Laravel package: service provider (auto-discovery), facade, publishable config, migrations, `paybridge:install`.
- Symfony bundle: config tree, services, Flex recipe (+ `paybridge:install` fallback), Doctrine migrations.
- Both: webhook route/controller, `paybridge:doctor`, `paybridge:test`, `paybridge:webhook:replay`, the dev-only demo UI (§11.3), the live-key guard.
- **Lock store**: the Laravel bridge's default `LockStore` must be backed by Laravel's own `Cache::lock()`, and the Symfony bridge's default must be backed by `symfony/lock` — **never** the core package's `Store\Cache\CacheLockStore` by default in either bridge. Both frameworks already ship a real atomic distributed lock; `CacheLockStore` is a generic, best-effort, non-atomic fallback for plain-PHP/dev use only (see its docblock).
- `bin/sandbox-app`, `docker-compose.sandbox.yml`, `.env.sandbox.example`.
- CI job: fresh Laravel + fresh Symfony app → install → `doctor` → `paybridge:test fake --scenario=success|declined|timeout --idempotency-check`.
- **DoD:** on a clean machine, the §11.1 commands work end-to-end with the Fake gateway in both frameworks, using SQLite and no Redis.

### Phase 6: Real drivers (one at a time; each one finished before the next)
1. **Stripe** (PaymentIntents API, `Idempotency-Key`, webhook `Stripe-Signature` scheme)
2. **PayPal** (Orders v2: create → approve redirect → capture, OAuth token caching, `PayPal-Request-Id`, webhook verification)
3. **BankTransfer** (generates a payment reference and instructions; status updated by webhook or `markPaid()`)
4. **Declarative** driver + `MappingSchema` validation (fail fast on a bad config with clear messages)

Check every provider's current API docs while implementing. Don't rely on memory for endpoint paths, header names, test card numbers or signature schemes.

- **DoD per driver:**
  - it passes the contract suite against recorded HTTP fixtures (PSR-18 mock client)
  - it has a `SandboxScenarios` class and `tests/Sandbox` tests
  - in a **fresh Laravel app and a fresh Symfony app**, `paybridge:doctor` is green and `paybridge:test {driver}` completes success + declined + idempotency-check against the real sandbox
  - its webhooks reach the demo UI locally (Stripe CLI / tunnel)

### Phase 7: Docs
- `docs/writing-a-driver.md`, `docs/declarative-driver.md`, `docs/resilience.md`, `docs/sandbox-testing.md` (step-by-step for Laravel and Symfony, with screenshots of `doctor`/`test` output).
- **DoD:** a new developer can add a declarative bank gateway and test it in a fresh app by following the docs, with no core changes.

---

## 13. Coding Conventions
- `declare(strict_types=1);` everywhere. Classes are `final` by default and DTOs are `readonly`.
- No static state, except an optional facade in the Laravel bridge.
- Constructor injection only, and every dependency is an interface.
- Each driver lives in its own namespace and never imports another driver.
- Public API changes need a CHANGELOG entry (SemVer).
- Every exception carries `gateway`, `operation`, `providerCode`, `httpStatus`, `retryable` and redacted `raw` context.

---

## 14. Definition of Done (v1.0)
- [ ] Stripe, PayPal, BankTransfer, Declarative and Fake drivers all pass the contract suite
- [ ] Idempotency, retry+jitter, timeouts, circuit breaker and TTL stores are covered by tests
- [ ] Webhooks are verified, deduplicated and protected against out-of-order delivery
- [ ] PHPStan level 9 is clean and coverage is ≥ 90% on `src/` excluding `Drivers/*/` HTTP glue
- [ ] Integrating into an existing Symfony or Laravel app takes ≤ 15 minutes using the bridge README
- [ ] From `composer create-project` to a successful Stripe and PayPal sandbox payment in a fresh Laravel **and** Symfony app takes ≤ 10 minutes, with no code written
- [ ] Live keys are refused by the test commands, and the demo routes do not exist outside dev
