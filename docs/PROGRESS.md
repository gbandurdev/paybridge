# PayBridge — Progress

Tracks phase status and decisions made while building against `docs/PLAN.md`. Updated as each phase completes.

## Phase 0: Skeleton — Done (2026-11-17)

**Scope:** `composer.json`, PHPUnit, PHPStan (level 9), PHP-CS-Fixer, GitHub Actions CI on PHP 8.3/8.4/8.5.

**DoD:** `composer test`, `composer stan`, `composer cs` all pass — verified locally on PHP 8.5.12 (only PHP version available in this environment; satisfies the `^8.3` constraint).

**Decisions made:**
- Core package named `paybridge/paybridge`; the Laravel/Symfony bridges (added in Phase 5) will be `paybridge/laravel` and `paybridge/symfony-bundle` per PLAN.md §11.1.
- License: MIT (not specified in PLAN.md; standard default for a Packagist library).
- No runtime `require` entries yet (`psr/log`, `psr/http-client`, `psr/clock`, etc.) — these get added in the phase that actually uses each interface, not speculatively.
- `composer.lock` is gitignored, not committed. CI runs `composer update` per PHP version in the matrix so each version resolves its own compatible dependency set.
- CS ruleset: `@PER-CS2.0` (confirmed against the installed PHP-CS-Fixer 3.95's `list-sets` output, matching PLAN.md §2's "PER-CS 2.0") plus an explicit `declare_strict_types` rule to match §13's coding convention.
- PHPStan level 9 errors on a completely empty `src/`+`tests/` ("No files found to analyse"), so `tests/Unit/ToolchainSmokeTest.php` was added as a minimal real smoke test (asserts `vendor/autoload.php` exists) — gives both PHPStan and PHPUnit something real to check. It can be deleted once Phase 1 adds real Domain/Status tests, or left as a standing toolchain check.
- Installed dev tool versions resolved by Composer: PHPUnit 11.5.58, PHPStan 2.3.6, PHP-CS-Fixer 3.95.32.

**Not yet verified:** the GitHub Actions matrix (8.3/8.4/8.5) itself — this repo has no git remote configured yet, so the workflow hasn't actually run in CI. Only local execution on PHP 8.5 has been confirmed.

**Update (2026-11-17):** minimum PHP bumped from 8.2 to 8.3 at the user's request — `composer.json` (`require.php` → `^8.3`, `config.platform.php` → `8.3.0`), `phpstan.neon.dist` (`phpVersion: 80300`), CI matrix → `8.3/8.4/8.5`, and `docs/PLAN.md` §2 and the Phase 0 task line updated to match. Re-verified `composer test`/`stan`/`cs` after the change.

## Phase 1: Domain + contracts — Done (2026-11-17)

**Scope:** `src/Domain/` (`Money`, `Currency`, `Capability`, `PaymentStatus`, `Customer`, `NextAction`/`NextActionType`, `PaymentRequest`, `CaptureRequest`, `RefundRequest`, `Payment`, `Refund`, `PaymentEvent`), `src/Status/` (`StateMachine`, `StatusMap`), `src/Exception/` (full hierarchy per PLAN.md §12/§13), `src/Contracts/` (`Gateway`, `Supports*`, `IdempotencyStore`, `LockStore`, `EventStore`, `Transport`/`TransportResponse`). No behavior/IO — pure value objects, enums and interfaces.

**DoD:** `composer test` (97 tests, 211 assertions), `composer stan` (level 9, clean) and `composer cs` all pass. Coverage (`phpunit --coverage-text` with Xdebug) is 100% methods/lines on every class in `Domain/` and `Status/` — verified via clover XML per-class breakdown, not just the aggregate number (the aggregate also folds in `Contracts/TransportResponse`, which is untested by design, see below).

**Decisions made:**
- No new Composer dependencies added. `Contracts/Transport` is deliberately decoupled from PSR-18/PSR-7 (takes method/url/headers/body primitives, returns a plain `TransportResponse` value object) so the PSR-18 adapter can be built in Phase 2 without pulling `psr/http-client`/`psr/http-factory` in before anything consumes them — same "add packages only when used" rule as Phase 0.
- `Capability` enum cases are the union of every capability named anywhere in PLAN.md (§3, §6.2): `AUTHORIZE`, `CAPTURE`, `REFUND`, `REFUND_PARTIAL`, `VOID`, `REDIRECT_FLOW`, `WEBHOOKS`, `THREE_DS`, `PROVIDER_IDEMPOTENCY` — PLAN.md never gives one authoritative list.
- `Contracts/SupportsWebhooks` takes raw `(string $payload, array $headers)` rather than a `WebhookRequest` DTO, since `Webhook/WebhookRequest.php` belongs to Phase 4 (`WebhookHandler`) per the directory layout — avoids forward-depending on a type that doesn't exist yet.
- `Contracts/SupportsRedirect` is an empty marker interface this phase — redirect completion goes through the existing `capture()`/`find()` flows per-driver; PLAN.md doesn't specify extra methods and Phase 6 driver needs aren't known yet.
- `StateMachine::canTransition()` tolerates same-status "transitions" (returns `true`) to support idempotent/duplicate webhook redelivery; actual event dedup is a separate, later concern (`EventStore`, Phase 4).
- `Money`/`Currency` reject floats at the type-hint boundary (`string`-only inputs under `strict_types=1`), reject negative amounts, and reject more fractional digits than the currency's ISO 4217 exponent allows.
- `IdempotencyConflict`'s reason is a backed enum (`IdempotencyConflictReason: IN_PROGRESS | PAYLOAD_MISMATCH`) rather than a bare string, for PHPStan-level type safety.
- Deleted the Phase 0 `tests/Unit/ToolchainSmokeTest.php` placeholder now that real Domain/Status tests exist.

**Next:** Phase 2 — Resilience core (`Pipeline` + middlewares, `RetryPolicy`, `ExponentialJitterBackoff`, `CircuitBreaker`, `RequestFingerprint`, in-memory stores, injectable `Clock`/`Sleeper`).

## Phase 2: Resilience core — Done (2026-11-19)

**Scope:** `Pipeline`/`Middleware` + all six middlewares (`Idempotency`, `CircuitBreaker`, `Retry`, `Timeout`, and pass-through `Logging`/`Metrics`), `RetryPolicy`, `ExponentialJitterBackoff`/`ConstantBackoff`, `CircuitBreaker`, `RequestFingerprint`, the in-memory stores (`IdempotencyStore`, `LockStore`, `EventStore`, plus a PSR-16 `InMemoryCache` backing the breaker), and injectable `Clock` (PSR-20)/`Sleeper`/`RandomSource` with production + fake implementations. Full design/rationale recorded in `docs/plans/phase-2.md` before implementation; see that doc for the per-middleware algorithms and test matrix.

**DoD:** `composer test` (229 tests, 674 assertions), `composer stan` (level 9, clean) and `composer cs` all pass. Coverage: 100% lines/methods on every new/touched class except `InMemoryCache` (PSR-16's `getMultiple`/`setMultiple`/`deleteMultiple`/`clear`/DateInterval-TTL paths are now covered too) — overall 96%+ lines, with the one intentional gap being `IdempotencyMiddleware::resultToArray()`'s defensive `LogicException` branch (unreachable through any real `Gateway` contract, which only ever returns `Payment`/`Refund`).

**Decisions made (beyond PLAN.md §6, full rationale in `docs/plans/phase-2.md`):**
- **`LockStore` interface changed** (Phase 1 contract): `acquire(): ?string` now returns a random owner token instead of `bool`; `release(string $key, string $token)` only deletes on a token match. Closes a real race (holder A's lock expires, B acquires, A's late `release()` must not delete B's lock) — covered by a dedicated test in both `InMemoryLockStoreTest` and `IdempotencyMiddlewareTest`.
- **Idempotency caching is asymmetric by exception type**: a *non-retryable* failure (e.g. `CardDeclined`) stores a `failed` record and replays on retry; a *retryable* failure (network blip, 503, breaker trip) stores nothing, so the next call with the same key tries again from scratch instead of replaying a stale outage for the full 24h TTL.
- **Exact exception class survives idempotency replay**: every `PaymentException` subtype got `toArray()`/`fromArray()`; a new `ExceptionSerializer` validates the stored class via `is_a(..., PaymentException::class, true)` before reconstructing, falling back to a generic `PaymentException` for an unresolvable/tampered class name instead of crashing. `Payment`/`Refund` got matching `toArray()`/`fromArray()` so stored records are plain arrays (JSON-able), never PHP `serialize()` — ready for Phase 4's Redis/PDO stores with no format change.
- **`NetworkException::ambiguous()` flag added**, replacing a blanket "no mutating retries without `PROVIDER_IDEMPOTENCY`" reading of §6.2 with the precise rule: only an *ambiguous* `NetworkException` (a timeout — might have reached the provider) is blocked for mutating ops lacking provider idempotency; `RateLimitedException`, `ProviderUnavailable`, and non-ambiguous `NetworkException` (e.g. connection refused before anything was sent) retry normally regardless. `TimeoutMiddleware` sets `ambiguous: true` when it wraps an unclassified timeout-shaped failure.
- **`CircuitOpen` now implements `RetryableException`**, so `IdempotencyMiddleware` treats a breaker trip like any other transient failure (stores nothing) instead of permanently caching it as a terminal failure for that idempotency key. Verified safe for `CircuitBreakerMiddleware` itself both structurally (its `throw CircuitOpen` sits outside the `try` that wraps `$next`, so it can never be fed back into its own failure-counting branch) and by a dedicated test asserting a burst of calls made entirely while open never changes `consecutiveFailures`/`openedAt`.
- **`CircuitBreaker` state is backed by PSR-16** (`Psr\SimpleCache\CacheInterface`, new `psr/simple-cache` dependency) rather than a bespoke store, matching §6.4's "shared across workers" requirement and §2's existing PSR-16 mention; `CircuitBreaker` (pure state machine) and `CircuitBreakerMiddleware` (exception-classification + wiring) are split per the directory layout.
- **New `psr/clock` dependency** for `Contracts\Clock extends Psr\Clock\ClockInterface`. `SystemClock` lives in `src/Clock/`; only test doubles (`FakeClock`, `FakeSleeper`, `FakeRandomSource`) live in `src/Testing/` — real implementations (`RealSleeper`, `SystemRandomSource`) sit beside their own interfaces in `src/Resilience/`.
- **`Logging`/`MetricsMiddleware` are intentionally pass-through** this phase — real PSR-3/PSR-14 wiring needs `Http/Redactor` (not built yet) and an actual logger/dispatcher from `PaymentManager::fromConfig()` (Phase 4); they exist now only so the `Pipeline`'s full six-middleware order is structurally in place.
- `PaymentException::fromArray()` deliberately never uses `new static()` (PHPStan-unsafe in a non-final base class) — it always builds a plain `PaymentException`. Every subtype that needs its own identity preserved on replay (including the "no extra fields" ones — `CardDeclined`, `AuthenticationFailed`, `UnsupportedOperation`, `InvalidSignature`, `InvalidRequest`, `CircuitOpen`) defines its own `fromArray()` using `new self()`, which is safe because each of those classes is `final`.

**Next:** Phase 3 — `FakeGateway` + `GatewayContractTestCase` (charge/find/refund/void/webhook verify-parse/error mapping/capability enforcement), proving the Phase 2 pipeline end-to-end through a real (fake) driver.
