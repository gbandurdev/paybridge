# Phase 3 — FakeGateway + contract test suite: design

## Context

Phase 0–2 are done per `docs/PROGRESS.md`. Per `CLAUDE.md`, work proceeds one phase at a time;
this is Phase 3 as scoped in `docs/PLAN.md` §12:

> `FakeGateway` with scriptable behaviour (`->willFail(NetworkException::class, times: 2)->thenSucceed()`).
> `GatewayContractTestCase`: charge, find, refund (full and partial), void, webhook verify/parse,
> error mapping, capability enforcement.
> **DoD:** FakeGateway passes the contract suite through the full middleware pipeline.

This revises the first draft of this plan after user feedback:

1. `createGateway()` and the success charge/find/refund scenarios are **required** (non-nullable)
   hooks. Only genuinely optional scenarios (redirect, void, webhooks, decline, capability
   variants) may return `null` to skip — and if the gateway's own `capabilities()` says it
   supports that scenario, a `null` hook now **fails** the test (naming the hook in the message)
   instead of silently skipping.
2. `FakeGateway::willFail()` is scoped **per operation**:
   `willFail(string $operation, string $exceptionClass, int $times)`, for `charge`, `find`,
   `refund`, `capture`, `void`.
3. The pipeline-wrapping decorator is **production code**, `src/Middleware/PipelineGateway.php`
   (`final`, implements `Gateway` + every `Supports*` interface, delegates through a
   `Middleware\Pipeline`), so Phase 4's `PaymentManager` reuses it instead of re-deriving it.

Grounded in the real Phase 1/2 code read directly from `src/` — `Contracts\Gateway` is minimal
(`name/capabilities/charge/find`); `authorize/capture/refund/void/webhooks` are optional
`Supports*` interfaces; `IdempotencyMiddleware` already treats `find()`/`void()` (no request DTO)
as bypassing idempotency; the §3 architecture diagram puts webhook verify/parse in a separate flow
from the `Logging → Metrics → Idempotency → CircuitBreaker → Retry → Timeout → Driver` pipeline;
`Pipeline`'s constructor binds one terminal callable, so `PipelineGateway` builds a fresh
`Pipeline` per call (same stored `list<Middleware>`, different terminal) rather than needing any
change to `Pipeline` itself.

---

## 1. `FakeGateway` (`src/Drivers/Fake/FakeGateway.php`)

`final class FakeGateway implements Gateway, SupportsAuthorize, SupportsCapture, SupportsRefund, SupportsVoid, SupportsWebhooks, SupportsRedirect`.

**Capability gating, not interface gating.** Every method (except core `charge()`/`find()`, which
are always available per the base `Gateway` contract) starts with `requireCapability(Capability
$c, string $op)`, throwing `UnsupportedOperation` before touching any state if `$c` isn't in the
constructor-supplied capability list — mirrors §3.2 ("throws `UnsupportedOperation` before any
network call"). `charge()`'s redirect branch is gated on `Capability::REDIRECT_FLOW`; its plain
success branch is unconditional (core).

**State:** `array<string, Payment>` keyed by `providerReference`. Every status change goes through
`(new StateMachine())->canTransition()` first (throws `InvalidRequest` on an illegal transition),
so the fake models real provider state rules instead of trivially succeeding. No separate
`FakeStatusMap` — FakeGateway never receives a foreign provider vocabulary to translate; a
`StatusMap` exists specifically for that translation, which doesn't apply here (`// DECISION`
comment in the code explains this).

**Operations:**
- `charge(PaymentRequest)` — on `$request->metadata['fake_trigger'] === 'decline'`: throws
  `CardDeclined`. On `'redirect'` (requires `Capability::REDIRECT_FLOW`): stores/returns
  `REQUIRES_ACTION` + `NextAction(REDIRECT, 'https://fake.test/approve/{ref}')`. Otherwise: stores
  `CAPTURED` with `capturedAmount = amount`.
- `authorize(PaymentRequest)` *(`Capability::AUTHORIZE`)* — stores `AUTHORIZED`, no captured amount.
- `capture(CaptureRequest)` *(`Capability::CAPTURE`)* — looks up, asserts the transition to
  `CAPTURED` is legal, sets `capturedAmount` to the given amount or the full authorized amount.
- `refund(RefundRequest)` *(`Capability::REFUND`)* — full refund (`amount: null`) only needs
  `REFUND`; a partial amount (< refundable) additionally needs `Capability::REFUND_PARTIAL`, else
  `UnsupportedOperation`. Updates `refundedAmount`/status via the state machine, returns `Refund`.
- `void(string $ref)` *(`Capability::VOID`)* — only legal from `AUTHORIZED` (the state machine's
  only `-> voided` edge), returns the updated `Payment`.
- `find(string $ref)` — returns the stored `Payment`; unknown reference throws `InvalidRequest`
  (no dedicated "not found" exception exists in the Phase 1 hierarchy — reusing `InvalidRequest`
  is a deliberate decision, not a new exception type, for a case PLAN.md never names).
- `verifySignature`/`parseEvent` *(`Capability::WEBHOOKS`)* — HMAC-SHA256: header
  `X-Fake-Signature` = `hash_hmac('sha256', $payload, FakeGateway::DEFAULT_WEBHOOK_SECRET)`,
  compared with `hash_equals`. Payload is JSON: `event_id`, `type`, `reference`, `status`
  (one of our own `PaymentStatus` values — no foreign vocabulary), `occurred_at` (ISO-8601,
  **required** in the fixture so `parseEvent()` stays deterministic — no `new
  DateTimeImmutable('now')` inside a supposedly-fake driver).
- Internal `lookup()` (no scripted-failure consumption, no capability check) backs
  `capture`/`refund`/`void`'s own lookups, kept separate from the public `find()` (which does
  consume `find`'s own scripted-failure queue) so scripting `find` doesn't leak into unrelated
  internal lookups performed by other operations.

**Scripted failures, per operation:**

```php
$fake->willFail('charge', NetworkException::class, times: 2)->thenSucceed();
```

`willFail(string $operation, string $exceptionClass, int $times = 1): self` validates `$operation`
is one of `charge|find|refund|capture|void` (else `InvalidArgumentException` — a test-authoring
mistake, not a `PaymentException`). Internally: `array<string, list<array{class: class-string<PaymentException>, remaining: int}>>`,
consumed at the top of each scriptable method (after its capability check, before its real logic):
pops/decrements the head of that operation's queue and `throw new $exceptionClass('Scripted
failure for the fake gateway.', gateway: $this->name, operation: $operation)`. Works for any
`PaymentException` subtype whose constructor accepts `$gateway`/`$operation` as named arguments —
i.e. every current subtype except `IdempotencyConflict` (needs a required `$reason`), which isn't
a sensible thing to script here anyway (idempotency conflicts come from the middleware detecting
a payload mismatch, not from "the provider"). `thenSucceed()` is a readable no-op terminator
(the queue draining to empty already means "succeed next time"), returns `$this`.

---

## 2. `PipelineGateway` (`src/Middleware/PipelineGateway.php`)

`final class PipelineGateway implements Gateway, SupportsAuthorize, SupportsCapture, SupportsRefund, SupportsVoid, SupportsWebhooks, SupportsRedirect`.

```php
public function __construct(
    private readonly Gateway $inner,
    /** @var list<Middleware> */
    private readonly array $middlewares,
) {}
```

`name()`/`capabilities()` delegate straight to `$inner`. Every mutating/read operation
(`charge/authorize/capture/refund/void/find`) builds an `OperationContext(name, operation,
request-or-null, $inner->capabilities())` and runs it through **a fresh `Pipeline($this->middlewares,
$terminal)`** per call (`Pipeline`'s constructor binds one terminal callable, so it can't be
reused across operations with different terminals — building a new `Pipeline` from the same
stored middleware list each call is the straightforward way to reuse it without changing
`Pipeline` itself). `void()`'s context carries `request: null` (confirmed safe: `IdempotencyMiddleware`
already bypasses itself on a null idempotency key, and the `$providerReference` string is captured
by the terminal closure, not routed through the context). `verifySignature`/`parseEvent` delegate
**directly to `$inner`, bypassing the pipeline entirely** — per the §3 architecture diagram,
webhooks are a separate flow.

Before delegating `authorize/capture/refund/void/verifySignature/parseEvent`, a private
`assertInnerSupports(string $interface, string $operation)` throws `UnsupportedOperation` if
`$inner` doesn't implement that `Supports*` interface, so a `PipelineGateway` wrapping a driver
that genuinely can't do something still fails fast and pre-network, matching §3.2.

**Important, documented consequence:** because `PipelineGateway` always structurally implements
every `Supports*` interface (per the instruction that it should), `$pipelineGateway instanceof
SupportsRefund` (etc.) is **always `true`**, regardless of whether `$inner` actually supports
it — it's a real (not vacuous) check when the wrapped driver is passed around un-wrapped, but a
PHP-level type-narrowing formality once wrapped, not a capability signal. **This is why the
contract suite below gates every scenario on `$gateway->capabilities()` membership, never on
`instanceof Supports*`** — `capabilities()` is the one signal `PipelineGateway` can't paper over,
since it transparently delegates to `$inner->capabilities()`. `instanceof` checks still appear in
the suite, but purely to narrow PHPStan's type after a capability check already decided the test
applies — asserted to hold, and failing loudly (not skipping) if it somehow doesn't.

---

## 3. `GatewayContractTestCase` (`tests/Contract/GatewayContractTestCase.php`)

Abstract hooks, split by the new required/optional rule:

**Required — must return real, usable data, never `null`:**
```php
abstract protected function createGateway(): Gateway;
abstract protected function successfulChargeRequest(): PaymentRequest;
```
`successfulChargeRequest()` must resolve (via `charge()`) to a terminal, non-`REQUIRES_ACTION`
status — asserted directly in `testChargeProducesASuccessfulPayment()`. It alone backs the
charge/find/full-refund/partial-refund test groups: no separate "refund scenario" hook is needed,
since refund operates on the `Payment` that `charge()` already produced.

**Capability-gated — nullable only when the matching capability is absent; `null` while the
capability *is* declared fails the test, naming the hook:**
```php
abstract protected function redirectTriggeringRequest(): ?PaymentRequest;      // Capability::REDIRECT_FLOW
abstract protected function voidableAuthorizeRequest(): ?PaymentRequest;       // Capability::VOID
abstract protected function validWebhookFixture(): ?WebhookFixture;           // Capability::WEBHOOKS
abstract protected function tamperedWebhookFixture(): ?WebhookFixture;        // Capability::WEBHOOKS
```
Enforced by a shared `assertCapabilityHookConsistency(mixed $scenario, Capability $c, Gateway
$gateway, string $hookName)`: fails if `$gateway->capabilities()` contains `$c` but `$scenario ===
null` ("`%s` declares %s but %s() returned null"), and symmetrically fails if `$c` is *absent* but
a non-null scenario was supplied anyway (same mismatch, other direction) — this is what makes the
suite a real consistency check between a driver's declared capabilities and its own test fixtures,
not just a one-way escape hatch.

**`REFUND_PARTIAL` deliberately has no dedicated hook.** Unlike `VOID`/`WEBHOOKS`/`REDIRECT_FLOW`
(which gate whether an *entire operation* is reachable at all, so a capability-removed variant is
the only way to exercise the negative path), `REFUND_PARTIAL` gates a *sub-behavior* of an
operation (`refund()`) that's already reachable via the required `successfulChargeRequest()`.
`testPartialRefundRespectsCapability()` branches on `$gateway->capabilities()` directly: present →
partial refund succeeds; absent → the identical call must throw `UnsupportedOperation`. Both
branches run against the **same** `createGateway()` instance, no variant needed.

**Pure optional — skip freely, not tied to any `Capability`:**
```php
abstract protected function decliningRequest(): ?PaymentRequest;                       // no Capability names "can decline"
abstract protected function createGatewayWithoutCapability(Capability $c): ?Gateway;    // "can this driver even construct a reduced variant"
```
`createGatewayWithoutCapability()` only backs the three enforcement tests that need a
capability-removed variant (`VOID`, `WEBHOOKS`, `REDIRECT_FLOW` — see above); a fixed-capability
real driver (Stripe) returns `null` for all of them and those three enforcement tests skip, while
every other assertion in the suite still runs against it normally.

### Test groups

1. `testChargeProducesASuccessfulPayment()` — required hook; asserts non-empty reference, status
   `≠ REQUIRES_ACTION`, amount round-trips.
2. `testFindReturnsConsistentState()` — `find()` after `charge()` agrees on reference/status/amount.
3. `testFullRefund()` — gated on `Capability::REFUND` (capabilities-based skip +
   `instanceof SupportsRefund` narrow-and-fail); refunds the full `refundableAmount()`, asserts
   `REFUNDED` and a zero `refundableAmount()` afterward.
4. `testPartialRefundRespectsCapability()` — both branches described above.
5. `testVoidRespectsCapability()` — gated on `Capability::VOID`; consistency-checks and consumes
   `voidableAuthorizeRequest()`; requires `instanceof SupportsAuthorize` to reach `AUTHORIZED`
   (fails loudly if a driver claims `VOID` + supplies the hook but can't authorize — voiding an
   already-captured payment isn't a real scenario); asserts `VOIDED`.
6. `testRedirectFlowWhenSupported()` — gated on `Capability::REDIRECT_FLOW`; asserts
   `REQUIRES_ACTION` + non-empty `NextAction::$url`.
7. `testWebhookVerifyAndParseRoundTrip()` — gated on `Capability::WEBHOOKS`; valid fixture →
   `verifySignature() === true` and `parseEvent()` returns non-empty `eventId`/`type`; tampered
   fixture → `verifySignature() === false`.
8. `testDeclineIsMappedToCardDeclined()` — optional (`decliningRequest()`); asserts `CardDeclined`,
   not `RetryableException`, and every `PaymentException` metadata field PLAN.md §13 requires,
   via one shared `assertPaymentExceptionShape()` helper reused by every exception-asserting test.
9. **Capability enforcement** — three dedicated tests (`testVoidCapabilityEnforcement()`,
   `testWebhookCapabilityEnforcement()`, `testRedirectFlowCapabilityEnforcement()`), each: skip
   unless the *current* gateway declares the capability (nothing to remove otherwise) → build
   `createGatewayWithoutCapability($capability)` → skip if `null` → else invoke the operation and
   assert `UnsupportedOperation` via the shared `expectUnsupportedOperation()` helper. (No fourth
   test for `REFUND_PARTIAL`: its negative path already lives inside `testPartialRefundRespectsCapability()`,
   see above — a separate enforcement test would just duplicate it.)

`expectUnsupportedOperation(callable $action, Gateway $gateway, string $operation)` and
`assertPaymentExceptionShape(PaymentException $e, Gateway $gateway, string $operation, bool
$retryable)` are the two shared assertion helpers every exception-related test group reuses, so
"error mapping" conventions are checked identically regardless of which exception or driver
produced it.

`WebhookFixture` (`tests/Contract/WebhookFixture.php`) is a tiny readonly DTO (`payload: string`,
`headers: array<string,string>`), test-only.

---

## 4. How a future driver (Stripe) reuses this suite

```php
final class StripeContractTest extends GatewayContractTestCase
{
    protected function createGateway(): Gateway
    {
        // Same production wiring PaymentManager will use — not a test-only shortcut.
        return new PipelineGateway(new StripeGateway($this->mockTransport(), apiKey: 'sk_test_fake'), $this->buildMiddlewares());
    }

    protected function successfulChargeRequest(): PaymentRequest
    {
        // Stripe's documented always-succeeds test payment method.
        return new PaymentRequest(..., metadata: ['stripe_test_pm' => 'pm_card_visa']);
    }

    protected function redirectTriggeringRequest(): ?PaymentRequest { /* pm_card_threeDSecure2Required, if Stripe declares REDIRECT_FLOW */ }
    protected function voidableAuthorizeRequest(): ?PaymentRequest { /* manual-capture PaymentIntent fixture, if VOID declared */ }
    protected function decliningRequest(): ?PaymentRequest { /* pm_card_visa_chargeDeclined */ }
    protected function validWebhookFixture(): ?WebhookFixture { /* real Stripe-Signature (t=.../v1=...) over a fixture payload */ }
    protected function tamperedWebhookFixture(): ?WebhookFixture { /* same payload, one byte flipped */ }

    protected function createGatewayWithoutCapability(Capability $c): ?Gateway
    {
        // Stripe's capability set is fixed by its real API — nothing to construct a variant of.
        return null;
    }
}
```

Nothing in `GatewayContractTestCase` changes. Stripe's test class supplies **fixture data and
trigger values only** — the identical `assertPaymentExceptionShape()` check that proves
`FakeGateway`'s `CardDeclined` carries `gateway()`/`operation()`/`retryable()` correctly is the
same check that proves Stripe's real `CardDeclined` (thrown by its `ErrorMapper` against a
recorded decline fixture) does too. `createGateway()` wrapping in `PipelineGateway` is no longer
a test-only convenience — it's literally how `PaymentManager` will construct every driver in
Phase 4, so every driver's contract test exercises the exact same resilience wiring real traffic
will go through, for free.

---

## 5. Supplementary `FakeGateway`-only tests (`tests/Unit/Drivers/Fake/FakeGatewayTest.php`)

Not part of the reusable suite — things that only make sense for a scriptable fake:
- `willFail('charge', NetworkException::class, times: 2)->thenSucceed()` through a
  `PipelineGateway`-wrapped instance: asserts `RetryMiddleware` retries exactly twice (via
  `FakeSleeper::callCount()`) before succeeding — the main reason `willFail()` exists.
- A burst of scripted non-retryable `CardDeclined` (`willFail('charge', CardDeclined::class,
  times: 10)`) never trips the circuit breaker (inner still called every time afterward).
- Capability rejection touches no state: `void()`/`refund()`/etc. on a capability-less
  `FakeGateway` leaves a pre-existing payment's `find()` result unchanged.
- `StateMachine` illegal-transition guard inside `FakeGateway` itself (e.g. voiding an
  already-captured payment throws `InvalidRequest`, independent of capability checks).

`tests/Unit/Middleware/PipelineGatewayTest.php` — ordinary production-code unit test (`PipelineGateway`
now lives under `src/Middleware/`, same convention as every other middleware): delegates
`name()`/`capabilities()`; routes each operation through the given middleware list (spy
middlewares, same style as the existing `PipelineTest`); `void()`'s context carries no request;
`verifySignature`/`parseEvent` never touch the middleware list; `UnsupportedOperation` thrown
(pre-pipeline) when the inner `Gateway` doesn't implement the needed `Supports*` interface.

---

## Files to add

```
src/Drivers/Fake/FakeGateway.php
src/Middleware/PipelineGateway.php
tests/Contract/GatewayContractTestCase.php
tests/Contract/WebhookFixture.php
tests/Contract/PipelineTestMiddlewares.php     (shared trait: builds the standard test-pipeline middleware list)
tests/Contract/FakeGatewayContractTest.php
tests/Unit/Drivers/Fake/FakeGatewayTest.php
tests/Unit/Middleware/PipelineGatewayTest.php
```

`phpunit.xml` needs a `tests/Contract` testsuite entry (PHPUnit skips the abstract base class
automatically; only `FakeGatewayContractTest` actually runs). No new Composer dependencies; no
changes to existing Phase 1/2 files besides that.

## Verification

- `composer test`, `composer stan` (level 9), `composer cs` all pass, per `CLAUDE.md`.
- `FakeGatewayContractTest` (which wraps `FakeGateway` in `PipelineGateway`) passing is the literal
  Phase 3 DoD.
- Update `docs/PROGRESS.md` with the decisions above once implemented.
