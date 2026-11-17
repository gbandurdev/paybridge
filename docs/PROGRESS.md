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
