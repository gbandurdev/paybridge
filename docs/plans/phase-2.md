# Phase 2 — Resilience core: algorithms & test plan

## Context

Phase 0 (skeleton) and Phase 1 (domain + contracts) are done per `docs/PROGRESS.md`. Per `CLAUDE.md`, work proceeds one phase at a time; this is a **planning-only** pass for Phase 2 ("Resilience core") as scoped in `docs/PLAN.md` §12:

> `Pipeline` plus all middlewares, `RetryPolicy`, `ExponentialJitterBackoff`, `CircuitBreaker`, `RequestFingerprint`, and the in-memory stores, using an injectable `Clock` and `Sleeper`.

This document explains, for each middleware/primitive, the exact algorithm (grounded in §6 of PLAN.md and the existing Phase 1 contracts/exceptions already in `src/`) and the test matrix that will prove the Phase 2 DoD.

Existing Phase 1 building blocks this design reuses (verified by reading the files directly, not from memory):
- `Contracts\IdempotencyStore::get/put`, `Contracts\LockStore::acquire/release`, `Contracts\EventStore::has/remember`
- `Exception\RetryableException` (marker), `NetworkException`, `RateLimitedException` (carries `retryAfterSeconds()`), `ProviderUnavailable` — all retryable; `InvalidRequest`, `CardDeclined`, etc. — not retryable
- `Exception\IdempotencyConflict` + `IdempotencyConflictReason::IN_PROGRESS|PAYLOAD_MISMATCH`
- `Domain\Capability::PROVIDER_IDEMPOTENCY` (and others)
- `Contracts\Gateway::capabilities(): list<Capability>`

No `Clock`, `Sleeper`, `Middleware`, `Pipeline`, or `Store/InMemory/*` exist yet — all new in Phase 2.

---

## New plumbing needed before the middlewares make sense

**`Clock`** — new `src/Contracts/Clock.php` implementing PSR-20 (`Psr\Clock\ClockInterface::now(): DateTimeImmutable`). Requires adding `psr/clock` to `composer.json` (justified — §2 names PSR-20 explicitly). `src/Clock/SystemClock.php` (production) + `src/Testing/FakeClock.php` (test double with `advance(int $seconds)`).

**`Sleeper`** — new `src/Resilience/Sleeper.php` (no PSR standard for this): `sleep(int $milliseconds): void`. `src/Resilience/RealSleeper.php` (`usleep`) + `src/Testing/FakeSleeper.php` (records calls, no real wait; optionally wraps a `FakeClock` and auto-advances it on each call so timing-dependent logic like the idempotency lock-wait loop stays internally consistent under test).

**Decision — `src/Testing/` holds fakes only.** `FakeClock`/`FakeSleeper` ship under `src/Testing/` (not `tests/Support/`), since Phase 2's own DoD says "TTL expiry works (by advancing a fake clock)" and later phases (contract tests, sandbox tests, bridge tests) will need the same doubles — same spirit as `Drivers/Fake/FakeGateway` in Phase 3. The real implementations live beside their own interfaces instead, not in `Testing/`: `SystemClock` in `src/Clock/`, `RealSleeper` in `src/Resilience/`.

**`OperationContext`** (name tentative) — not named anywhere in PLAN.md, but every middleware needs a shared value to pass down the chain: `gateway` (string), `operation` (string, e.g. `charge`/`refund`/`capture`/`void`/`find`), the request DTO if any (`PaymentRequest|RefundRequest|CaptureRequest|null`), and `capabilities` (`list<Capability>`, from `Gateway::capabilities()`) so Retry/Idempotency can reason about PROVIDER_IDEMPOTENCY. Treated as an immutable value with `with*` methods (e.g. `withTimeoutMs()`) so middlewares can attach data for inner stages without mutating shared state.

**`Middleware` interface** — `process(OperationContext $context, callable $next): mixed`, onion-style (PSR-15 shape, but for gateway operations, not HTTP). **`Pipeline`** composes an ordered list of middlewares around a terminal callable (the actual driver call) and exposes `run(OperationContext $context): mixed`.

Pipeline order, per the architecture diagram in PLAN.md §3:
```
Logging → Metrics → Idempotency → CircuitBreaker → Retry → Timeout → Driver
```
This placement matters: **CircuitBreaker sits outside Retry**, so the breaker only ever observes the *final* outcome of a fully-retried operation call, not each individual attempt inside the retry loop. "N consecutive retryable failures" (§6.4) therefore means N separate top-level operation calls that each exhausted their retries and still failed — not N raw attempts within one call. This reading drives the CircuitBreaker test design below.

---

## 1. `RequestFingerprint` (§6.1)

**Algorithm:**
1. Convert the request DTO (`PaymentRequest`/`RefundRequest`/`CaptureRequest`) to an associative array of its public fields.
2. Drop the `idempotencyKey` field.
3. Normalize nested value objects to primitives consistently: `Money` → `['amount_minor' => int, 'currency' => string]`; `Customer` → its field array; enums → `->value`.
4. Recursively sort array keys (`ksort` at every level, including `metadata`) so field/insertion order never affects the result.
5. `json_encode` with fixed flags, then `hash('sha256', ...)`.

**Test cases:**
- Same request, only `idempotencyKey` differs → same fingerprint.
- Changing any other field (amount, currency, orderId, description, returnUrl, metadata, customer) → different fingerprint.
- `metadata` with identical key/value pairs inserted in a different order → same fingerprint (tests the recursive `ksort`).
- Two `Money` values that are numerically equal but constructed differently (`Money::of('10.00','EUR')` vs `Money::ofMinor(1000,'EUR')`) → same fingerprint.
- Output is deterministic across repeated calls (no time/randomness leakage) and is a 64-char lowercase hex string.
- Works for all three mutating DTOs (`PaymentRequest`, `RefundRequest`, `CaptureRequest`), each stripping its own `idempotencyKey`.

---

## 2. `IdempotencyMiddleware` (§6.1)

Only wraps mutating operations (ones whose request carries an `idempotencyKey`); `find()` has no key, so the middleware short-circuits straight to `$next` when `context` carries no idempotency key — this keeps the middleware safe to place in the pipeline regardless of operation.

**`LockStore` interface change (from Phase 1):** `acquire(string $key, int $ttlSeconds): ?string` now returns a random opaque **owner token** on success (e.g. `bin2hex(random_bytes(16))`), or `null` if the lock is already held by someone else. `release(string $key, string $token): void` only deletes the entry if its currently-stored token still matches `$token` — a release carrying a stale or foreign token is a silent no-op. This closes the classic race: holder A's lock expires, holder B acquires the now-free key (getting a *new* token), and A's late `release()` call — unaware of B — must not delete B's lock. `InMemoryLockStore` stores `[key => ['token' => string, 'expiresAt' => DateTimeImmutable]]`; `acquire` treats an absent or expired entry as free, mints a fresh token, and overwrites the entry; `release` compares tokens before deleting.

**Algorithm:**
1. If the request has no idempotency key → call and return `$next($context)` directly (no store/lock interaction).
2. `fingerprint = RequestFingerprint::compute($request)`.
3. `key = "idem:{gateway}:{idempotencyKey}"`.
4. Try `token = lock.acquire(key, lockTtl)` (default 30s per §6.5). If `null`, poll (sleep small increments via `Sleeper`) and retry acquisition until either it succeeds (keep the returned `token`) or the elapsed wait reaches `lockTtl`; if still not acquired → throw `IdempotencyConflict(IN_PROGRESS)`.
5. Wrap the rest in `try/finally` so the lock is always released exactly once, using the **same `token`** this call acquired:
   - `record = store.get(key)`.
   - If found and `record.fingerprint !== fingerprint` → throw `IdempotencyConflict(PAYLOAD_MISMATCH)`.
   - If found and `record.status === 'completed'` → reconstruct (`Payment::fromArray()`/`Refund::fromArray()`) and return the stored result, **without calling `$next`**.
   - If found and `record.status === 'failed'` → reconstruct via `ExceptionSerializer::fromArray()` (see below) and rethrow the stored failure, **without calling `$next`**.
   - If not found → call `$next($context)`:
     - success → `store.put(key, {fingerprint, status: completed, response: Payment::toArray()/Refund::toArray()}, ttl)` (default 86400s), return the result.
     - exception `$e`:
       - **if `$e instanceof RetryableException` → store nothing.** Retry sits *inside* Idempotency in the pipeline, so by the time this middleware sees a retryable failure, Retry has already exhausted every attempt *for this call* — but a retryable failure (network blip, 503, breaker trip) is a verdict on the provider's availability, not on this payload. Caching it would force every subsequent call with the same key to replay today's transient outage for the full TTL. Leaving no record means the next call starts clean and may succeed.
       - else (non-retryable, e.g. `CardDeclined`, `InvalidRequest`) → `store.put(key, {fingerprint, status: failed, error: ExceptionSerializer::toArray($e)}, ttl)` — this one really is terminal for this exact payload.
     - rethrow `$e` either way.
   - `finally`: `lock.release(key, token)`.

**`CircuitOpen implements RetryableException`:** `CircuitOpen` represents the same "we didn't really try" situation as a transient retryable failure. Without this, a `CircuitOpen` thrown while the breaker is tripped would get cached as a terminal `failed` idempotency record under the rule above, poisoning that idempotency key for the full TTL even after the circuit recovers. This is safe for the breaker itself — see §5's explicit guarantee — because `CircuitOpen` is only ever thrown by `CircuitBreakerMiddleware` at a point *before* it calls into `RetryMiddleware`, so `RetryMiddleware` can never receive one to retry, and `CircuitBreakerMiddleware` can never receive one back from its own `$next` call either.

**Exception serialization:** every `PaymentException` subtype gets `toArray(): array` (shape `['class' => static::class, 'data' => [...fields...]]`) and a matching static `fromArray(array $data): static`, so each subtype's own extra fields (e.g. `RateLimitedException::$retryAfterSeconds`, `IdempotencyConflict::$reason`) round-trip exactly. A new `Exception\ExceptionSerializer` helper does the untrusted-input-safe dispatch: `toArray(PaymentException $e): array` delegates to `$e->toArray()`; `fromArray(array $record): PaymentException` checks `is_a($record['class'], PaymentException::class, true)` *before* calling `$record['class']::fromArray($record['data'])` — refusing to instantiate anything that isn't a real `PaymentException` subtype (guards against a stored record naming a class that was renamed/removed in a later library version, or — once Phase 4's Redis/PDO-backed stores land — a tampered record). An unrecognized class falls back to a generic `PaymentException` built from whatever fields are present, instead of a confusing low-level error.

**Response serialization:** stored `response` data is a normalized plain array via new `Payment::toArray()`/`Payment::fromArray()` and `Refund::toArray()/fromArray()` (nested value objects flattened the same way as `RequestFingerprint` does — `Money` → `{amount_minor, currency}`, etc.) — never PHP's `serialize()`/`unserialize()`. This is what lets the exact same record shape work unchanged against Redis (`json_encode`'d string) or a PDO JSON column once Phase 4 adds those backends.

**Test cases:**
1. No existing record, lock free → inner called exactly once; completed record stored (as a plain array via `Payment::toArray()`); result returned.
2. Same key + same fingerprint, stored record `completed` → inner **not** called; cached result reconstructed via `Payment::fromArray()` and returned.
3. Same key + different fingerprint → `IdempotencyConflict(PAYLOAD_MISMATCH)`; inner not called.
4. Same key + same fingerprint, stored record `failed` (from a prior **non-retryable** exception, e.g. `CardDeclined`) → the *exact original exception class* is reconstructed and rethrown (not a generic `PaymentException`), with its original message/gateway/operation/providerCode/httpStatus/raw.
5. Inner throws a **retryable** exception (simulating Retry having exhausted all attempts) → **no record is stored**; the exception is rethrown; a second call with the same key afterward calls inner again from scratch (not blocked, not replayed) — proves transient failures don't poison the idempotency cache.
6. Inner throws a **non-retryable** exception → a `failed` record is stored; a second call with the same key + same fingerprint gets the replayed exception instead of calling inner again.
7. Concurrent same key, second caller's `lock.acquire` returns `null` until the first releases: (a) first completes before the second's wait budget elapses → second gets the cached result; (b) first doesn't finish before `lockTtl` → second throws `IdempotencyConflict(IN_PROGRESS)`.
8. Lock held by a "stuck" caller for the whole wait budget → `IN_PROGRESS` thrown; verify `FakeSleeper`'s total slept ≈ `lockTtl`, not real wall time.
9. Inner throws → lock is still released (with the correct token) afterward; a subsequent call can acquire it.
10. Request without an idempotency key (`find()`) bypasses the middleware entirely — no store/lock calls at all.
11. Same idempotency key value used on two different gateway names → independent records (namespacing by gateway).
12. Record stored with a short TTL; after advancing `FakeClock` past expiry, a repeat call treats it as absent and calls inner again.
13. A stored record whose `error.class` doesn't resolve to a `PaymentException` subtype (simulating a stale/renamed class) → `ExceptionSerializer::fromArray()` falls back to a generic `PaymentException` rather than crashing.
14. **`LockStore` race test:** holder A acquires `key` and gets `token=A`; A's lock expires (via `FakeClock` advance) without A releasing; holder B acquires the same `key` and gets a different `token=B`; A then calls `release(key, A)` — B's lock entry (and `token=B`) must remain intact afterward (verified by B still being able to `release(key, B)` successfully, or by a third party's `acquire(key, ...)` still failing until B's own TTL or release).

---

## 3. `RetryPolicy` + Backoff + `RetryMiddleware` (§6.2)

**`RetryPolicy::shouldRetry(Throwable $e, int $attempt, int $maxAttempts, string $operationKind, list<Capability> $capabilities): bool`**

Decision logic:
- `false` if `$e` doesn't implement `RetryableException`.
- `false` if `$attempt >= $maxAttempts`.
- **Precise gating via a `NetworkException::ambiguous()` flag.** `NetworkException` gains a constructor flag `ambiguous: bool` / accessor `ambiguous(): bool`: `true` means the request may already have reached the provider (a timeout — we gave up waiting, we don't know if it was received); `false` means it's safe to assume nothing happened provider-side (e.g. connection refused before a byte was sent). For mutating operations (`charge`/`capture`/`refund`/`void`) without `Capability::PROVIDER_IDEMPOTENCY`: retry normally for `RateLimitedException`, `ProviderUnavailable`, and non-ambiguous `NetworkException` — only an **ambiguous** `NetworkException` is blocked (fails fast after attempt 1), since that's the one case where a blind retry could double-charge with no provider-side dedup to catch it. Gateways that *do* declare `PROVIDER_IDEMPOTENCY`, and non-mutating `find()`, always follow the normal retry policy regardless of ambiguity.
- Otherwise `true`.

**`ExponentialJitterBackoff::delayMs(int $attempt, int $baseMs, int $maxMs): int`** = `random(0, min(maxMs, baseMs * 2^attempt))`. Needs an injectable random source (e.g. `RandomSource::nextInt(min, max)`, default `random_int`) so tests can force deterministic bounds rather than just asserting a range.

**`ConstantBackoff::delayMs(int $fixedMs): int`** — returns a fixed value; used to honor `Retry-After`.

**`RetryMiddleware` algorithm:**
1. `attempt = 1`.
2. Loop:
   - Call `$next($context)`; on success, return the result (no sleep).
   - On exception `$e`:
     - If `RetryPolicy::shouldRetry($e, $attempt, ...)` is `false` → rethrow `$e` immediately.
     - Else compute delay: if `$e instanceof RateLimitedException` and `retryAfterSeconds() !== null` → `delay = min(retryAfterSeconds*1000, maxMs)` (via `ConstantBackoff`); else `delay = ExponentialJitterBackoff::delayMs(attempt, baseMs, maxMs)`.
     - `sleeper.sleep(delay)`; `attempt++`; loop again.

**Test cases — `ExponentialJitterBackoff`:**
- RNG forced to its upper bound → result equals `min(maxMs, baseMs * 2^attempt)` exactly, for several `attempt` values.
- RNG forced to 0 → result is 0.
- Large `attempt` where `baseMs * 2^attempt` would exceed `maxMs` → forced-max result saturates at exactly `maxMs` (no overflow past the cap).

**Test cases — `ConstantBackoff`:** returns the fixed value regardless of attempt number.

**Test cases — `RetryPolicy`:**
1. Retryable + under max attempts + `find` (non-mutating) → retry.
2. Non-retryable exception (e.g. `CardDeclined`) → never retry, any attempt count.
3. Retryable but `attempt >= maxAttempts` → no retry.
4. Mutating op, gateway lacks `PROVIDER_IDEMPOTENCY`, `RateLimitedException` → retry (allowed — not gated by ambiguity).
5. Mutating op, gateway lacks `PROVIDER_IDEMPOTENCY`, `ProviderUnavailable` → retry (allowed).
6. Mutating op, gateway lacks `PROVIDER_IDEMPOTENCY`, non-ambiguous `NetworkException` → retry (allowed).
7. Mutating op, gateway lacks `PROVIDER_IDEMPOTENCY`, **ambiguous** `NetworkException` → no retry (blocked — the one real gap).
8. Mutating op, gateway **has** `PROVIDER_IDEMPOTENCY`, ambiguous `NetworkException` → retry (capability removes the restriction entirely).

**Test cases — `RetryMiddleware`:**
1. Inner fails `k` times (retryable) then succeeds (`k < maxAttempts`) → result returned, inner called `k+1` times, `Sleeper` called `k` times with delays within the expected bounds for attempts `1..k`.
2. Inner always fails (retryable) → after `maxAttempts` calls, the last exception is rethrown; `Sleeper` called `maxAttempts - 1` times.
3. Inner fails once, non-retryable → rethrown immediately; inner called once; `Sleeper` never called.
4. `RateLimitedException` with `retryAfterSeconds` set → the exact sleep duration used is `min(retryAfterSeconds*1000, maxMs)`, not the exponential formula (spy `Sleeper` captures the literal ms argument).
5. Same as above but `retryAfterSeconds` would exceed `maxMs` → sleep capped at `maxMs`.
6. Mutating operation + **ambiguous** `NetworkException` + gateway lacking `PROVIDER_IDEMPOTENCY` → fails fast after one attempt (confirms the middleware actually consults operation kind, capabilities, and the ambiguity flag — not just the exception type).
7. Mutating operation + `RateLimitedException` (or non-ambiguous `NetworkException`) + gateway lacking `PROVIDER_IDEMPOTENCY` → retries normally, same as any other retryable failure.

---

## 4. `TimeoutMiddleware` (§6.3)

No real HTTP transport exists yet (Phase 2 scope stops at resilience primitives + in-memory stores — `Http/Psr18Transport.php` is a later phase), so this middleware can't enforce a *real* connect/read timeout yet. Its Phase-2 job, scoped to what's testable now:
1. Attach the configured `timeoutMs` onto the context (`$context->withTimeoutMs($timeoutMs)`) before calling `$next`, so a future driver/transport can read and apply it.
2. Measure elapsed time around the inner call using the injected `Clock` (`start = clock.now()`).
3. On success → return the result unchanged.
4. On exception `$e`:
   - If `$e` is already a `PaymentException` (already classified by an inner layer) → rethrow unchanged.
   - Else (an unclassified throwable) → compute `elapsedMs` from the clock; if `elapsedMs >= timeoutMs`, treat it as a timeout and throw `NetworkException("... timed out after {elapsedMs}ms", gateway, operation, ambiguous: true, previous: $e)` (retryable, and **ambiguous** — a timeout can never tell us whether the provider received the request before we gave up); otherwise rethrow `$e` unchanged (not a timeout — don't miscategorize an unrelated bug).

Real enforcement (actually aborting a slow HTTP call) becomes the Transport's job once `Psr18Transport` exists; this middleware's contract (attach budget, normalize timeout-shaped failures) doesn't change when that lands.

**Test cases:**
1. Inner succeeds quickly → result passed through unchanged.
2. Inner throws a `PaymentException` subtype → rethrown unchanged regardless of elapsed time (not re-wrapped).
3. Inner throws a generic `\Throwable`, with `FakeClock` advanced past `timeoutMs` before the throw → wrapped as `NetworkException` with `ambiguous() === true` (retryable), original exception preserved as `$previous`.
4. Same as above but `FakeClock` elapsed is under `timeoutMs` → original exception rethrown unmodified.
5. The `timeoutMs` value is readable from the context object passed into `$next` (wiring check for future transport use).

---

## 5. `CircuitBreaker` + `CircuitBreakerMiddleware` (§6.4)

**State storage:** §6.4 says breaker state must live "in the cache store so it is shared across workers." §2 names PSR-16 for simple TTL caching. **Decision:** back `CircuitBreaker` with a PSR-16 `Psr\SimpleCache\CacheInterface` (new `psr/simple-cache` dependency), with a Phase-2 in-memory implementation (`Store/InMemory/InMemoryCache.php`) that uses the injected `Clock` for TTL instead of real wall-clock, so tests can fast-forward. Real adapters (Laravel `Cache`, Symfony `cache.app`, Redis) are Phase 4/5.

State record per gateway, key `cb:{gateway}`: `{state: closed|open|half_open, consecutiveFailures: int, openedAt: ?int}`.

**Algorithm (`CircuitBreakerMiddleware::process`):**
1. Load the gateway's state (default: closed, 0, null if missing).
2. If `state === open`:
   - If `now >= openedAt + cooldownSeconds` → transition to `half_open`, persist, allow this one call through as a trial.
   - Else → throw `CircuitOpen` immediately, **inner not called**.
3. Otherwise (`closed` or `half_open`) → call `$next($context)`.
4. On success:
   - If it was a `half_open` trial → transition to `closed`, `consecutiveFailures = 0`.
   - If it was `closed` → reset `consecutiveFailures = 0` if nonzero.
   - Persist; return the result.
5. On failure `$e`:
   - If `$e` is **not** a `RetryableException` (e.g. a decline) → breaker state untouched entirely (doesn't count, doesn't reset); rethrow.
   - If it **is** retryable (meaning Retry, which sits inside this middleware, already exhausted its attempts) → increment `consecutiveFailures`:
     - was `half_open` → immediately back to `open`, `openedAt = now` (one failed trial reopens, doesn't go through "closed with count 1").
     - was `closed` and `consecutiveFailures >= failureThreshold` → transition to `open`, `openedAt = now`.
     - else → persist the incremented count, stay `closed`.
   - Rethrow `$e` either way — `CircuitOpen` is only raised at step 2, never used to swallow the real failure on the call that trips the breaker.

**Guarantee — the breaker never counts its own `CircuitOpen` as a failure.** Step 2's `throw CircuitOpen` happens *before* the `try` block that wraps step 3's `$next($context)` call; step 5's failure handling only ever sees exceptions that came out of that `try`. So a `CircuitOpen` thrown by this method can structurally never flow back into its own step 5 — there is no code path where it could. (This is also why making `CircuitOpen implements RetryableException`, per §2, is safe for the breaker itself: even though `RetryMiddleware`, sitting inside, would now be willing to retry a `CircuitOpen`, it can never receive one, since `CircuitOpen` is only ever thrown by `CircuitBreakerMiddleware` at step 2, outside of and prior to calling into `RetryMiddleware` at all.)

**Test cases:**
1. Closed, failures below threshold: each retryable failure rethrows the original exception, breaker stays closed, inner called every time.
2. The Nth (threshold-reaching) consecutive retryable failure trips the breaker to open, but that call's own exception is still the real one (not `CircuitOpen`).
3. Open, next call before cooldown elapses → `CircuitOpen` thrown immediately; inner **not called**.
4. Open, cooldown elapsed (via `FakeClock` advance) → transitions to half-open and lets exactly one trial call through.
5. Half-open trial succeeds → closes, `consecutiveFailures` reset to 0; next call goes through normally.
6. Half-open trial fails (retryable) → reopens immediately; the very next call (before a fresh cooldown) → `CircuitOpen` again.
7. Non-retryable failures (e.g. simulated `CardDeclined`), even many in a row, never increment the counter and never trip the breaker; inner always called, real exception always rethrown.
8. Breaker state is isolated per gateway — tripping `stripe` doesn't affect `paypal`.
9. A success after some sub-threshold closed-state failures resets the counter to 0 (next failure streak starts fresh, no carry-over).
10. Two separate `CircuitBreakerMiddleware` instances sharing the same underlying `InMemoryCache` (simulating two worker processes) observe consistent state — one trips it open, the other immediately sees `open` and throws `CircuitOpen` without calling inner.
11. While open, repeatedly calling through the breaker (each call short-circuiting on `CircuitOpen`) never changes `consecutiveFailures` and never re-triggers the open transition logic — the stored state (`open`, same `openedAt`) is identical before and after a burst of calls made entirely while open, proving `CircuitOpen` itself is never fed back into the failure-counting branch.

---

## 6. In-memory stores (Phase 2 scope: "the in-memory stores")

All use the injected `Clock` for TTL math (never real wall-clock), so tests can fast-forward deterministically.

**`InMemoryIdempotencyStore`** (`Contracts\IdempotencyStore`):
- `get`: absent or `now >= expiresAt` → `null`; else the record.
- `put`: stores `record` with `expiresAt = now + ttlSeconds`.

Tests: put→get round-trip; get on missing key is null; expired record (via `FakeClock::advance`) reads as null; a record read just before expiry still returns; `put` on an existing key overwrites value + resets expiry.

**`InMemoryLockStore`** (`Contracts\LockStore`, token-based per §2's interface change):
- `acquire`: absent or stale (`now >= storedExpiry`) → mint a fresh token, set `{token, expiry: now + ttl}`, return the token; else return `null`.
- `release(key, token)`: delete the entry only if its stored token equals `$token`; otherwise no-op.

Tests: acquire-then-blocked-acquire; release(correct token)-then-reacquire; stale lock self-heals after TTL passes without release; releasing with a wrong/stale token is a silent no-op and leaves the current holder's entry untouched (the §2 test case 14 scenario, exercised directly against the store); two different keys don't interfere.

**`InMemoryEventStore`** (`Contracts\EventStore`) — lighter scope (full webhook dedup is Phase 4), included because the interface already exists and the store itself is cheap:
- `has`: membership check against non-expired entries.
- `remember`: stores with TTL.

Tests: `has` false before `remember`; true after; false again after TTL passes (`FakeClock` advance).

**`InMemoryCache`** (new `Psr\SimpleCache\CacheInterface` implementation, backing `CircuitBreaker`): `get`/`set`/`delete` subset actually used, TTL via `Clock`.

Tests: set→get round-trip; get on missing key returns the PSR-16 default; TTL expiry via `FakeClock` advance returns default again.

---

## 7. `Logging`/`MetricsMiddleware` — scoped down for Phase 2

PLAN.md §12's Phase 2 line says "all middlewares," and the directory layout (§4) lists `LoggingMiddleware.php`/`MetricsMiddleware.php`, but the Phase 2 **DoD** (§12) never tests them, and fully wiring §10's observability spec (PSR-3 structured logging with redaction, PSR-14 events, a `MetricsCollector` interface) needs pieces that don't exist yet: `Http/Redactor.php` (not in Phase 2 scope) and a `MetricsCollector` contract that isn't named anywhere in §4's directory listing.

**Decision:** keep both as structurally-present, minimal pass-through middlewares in Phase 2 — they exist in the `Middleware/` namespace and participate correctly in the `Pipeline` (so "all middlewares" is satisfied structurally), but carry no new dependencies (no `psr/log` yet) and do no real logging/metrics work yet. Real wiring happens once `PaymentManager::fromConfig()` (Phase 4) actually has a logger/dispatcher to inject, and once `Redactor` exists to safely log payloads.

**Test cases (minimal):** each middleware calls `$next` exactly once, returns its result unchanged on success, and lets exceptions propagate unchanged on failure — i.e. provably a no-op wrapper for now.

---

## 8. `Pipeline` / `Middleware` interface itself

Tests:
1. Middlewares run in the configured order — assert via fake middlewares that each append `before`/`after` markers to a shared log around `$next`, producing the expected onion trace (e.g. `[A-before, B-before, terminal, B-after, A-after]`).
2. A middleware that doesn't call `$next` short-circuits everything inside it (including the terminal callable).
3. Empty middleware list → terminal callable invoked directly.
4. An exception from the terminal callable propagates through every middleware unchanged when none of them catch it.
5. Context mutations via `with*` (e.g. `TimeoutMiddleware` attaching `timeoutMs`) are visible to inner middlewares and the terminal callable.

---

## Files to add

```
src/Clock/SystemClock.php
src/Testing/FakeClock.php
src/Resilience/Sleeper.php
src/Resilience/RealSleeper.php
src/Testing/FakeSleeper.php
src/Resilience/RandomSource.php (+ default + fake)
src/Resilience/RequestFingerprint.php
src/Resilience/RetryPolicy.php
src/Resilience/Backoff/ExponentialJitterBackoff.php
src/Resilience/Backoff/ConstantBackoff.php
src/Resilience/CircuitBreaker.php
src/Middleware/OperationContext.php (naming TBD)
src/Middleware/Middleware.php
src/Middleware/Pipeline.php
src/Middleware/LoggingMiddleware.php        (pass-through, Phase 2)
src/Middleware/MetricsMiddleware.php        (pass-through, Phase 2)
src/Middleware/IdempotencyMiddleware.php
src/Middleware/CircuitBreakerMiddleware.php
src/Middleware/RetryMiddleware.php
src/Middleware/TimeoutMiddleware.php
src/Store/InMemory/InMemoryIdempotencyStore.php
src/Store/InMemory/InMemoryLockStore.php
src/Store/InMemory/InMemoryEventStore.php
src/Store/InMemory/InMemoryCache.php
src/Exception/ExceptionSerializer.php
```
New composer dependencies: `psr/clock`, `psr/simple-cache`.

**Modifications to existing Phase 1 files (kept minimal/additive, not a redesign):**
```
src/Contracts/Clock.php                 (new, was missing)
src/Contracts/LockStore.php             acquire(): ?string, release(string $key, string $token): void
src/Exception/PaymentException.php      + toArray()/fromArray()
src/Exception/NetworkException.php      + ambiguous: bool flag + ambiguous(): bool, + toArray()/fromArray() override
src/Exception/RateLimitedException.php  + toArray()/fromArray() override (carries retryAfterSeconds)
src/Exception/IdempotencyConflict.php   + toArray()/fromArray() override (carries reason enum)
src/Exception/CircuitOpen.php           implements RetryableException
every other Exception subtype           + toArray()/fromArray() (base impl usually suffices)
src/Domain/Payment.php                  + toArray()/fromArray()
src/Domain/Refund.php                   + toArray()/fromArray()
```

## Verification

- `composer test`, `composer stan` (level 9), `composer cs` all pass, per `CLAUDE.md`.
- Map directly to the Phase 2 DoD bullets in PLAN.md §12:
  - retry-up-to-max with correct backoff bounds / non-retryable not retried → §3 test cases
  - `Retry-After` honored → §3 test cases 4–5
  - same key+payload → single inner call; different payload → `IdempotencyConflict` → §2 test cases 1–4
  - concurrent same key → one wins, other gets cached result or `in_progress` → §2 test case 5–6
  - breaker opens/half-opens/closes correctly → §5 test cases 1–6
  - TTL expiry via fake clock → §6 store tests + §2 test case 12
  - retryable idempotency failures aren't cached, non-retryable ones are, and the exact exception class survives replay → §2 test cases 4–6, 13
  - stale lock release can't steal a new holder's lock → §2 test case 14
  - mutating-op retry gating is precise (ambiguous `NetworkException` only, not a blanket PROVIDER_IDEMPOTENCY gate) → §3 `RetryPolicy`/`RetryMiddleware` test cases
- Update `docs/PROGRESS.md` with the same decisions recorded above once implemented.
