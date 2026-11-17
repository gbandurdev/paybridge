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

**Next:** Phase 1 — Domain + contracts (`Money`, `Currency`, DTOs, `PaymentStatus`, `Capability`, `StateMachine`, exception hierarchy, interfaces).
