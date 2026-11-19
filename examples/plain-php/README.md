# PayBridge — plain PHP example

A single walkthrough script proving the Phase 4 wiring end-to-end against `FakeGateway`:
charge → idempotent replay → partial refund → webhook dedup (and a stale-event ignore) →
reconciliation → PDO housekeeping (`Pruner`). It runs unmodified against either the PDO
(SQLite) or the Redis store backend, selected by an environment variable — proving the stores
really are interchangeable through the same `PaymentManager::fromConfig()` call.

## Run it

From the repository root (no separate `composer install` needed — it reuses the monorepo's own
`vendor/autoload.php`):

```bash
# PDO / SQLite (default) — no extra infrastructure needed beyond the bundled pdo_sqlite extension
php examples/plain-php/run.php

# Redis — needs ext-redis and a local redis-server (defaults to redis://127.0.0.1:6379)
PAYBRIDGE_STORE_DRIVER=redis php examples/plain-php/run.php
# or point at a different server:
PAYBRIDGE_STORE_DRIVER=redis PAYBRIDGE_REDIS_DSN=redis://localhost:6380 php examples/plain-php/run.php
```

The SQLite file is created at `examples/plain-php/data/paybridge.sqlite` on first run (migrated
automatically by `Store\Pdo\Migrator`, via `Store\StoreFactory`). Every idempotency key, event id
and order id is suffixed with a random id generated once per run, so re-running the script
against either backend always shows the full walkthrough again instead of idempotency-replaying
a previous run's results end-to-end.

## What it proves, step by step

1. **Charge** — `PaymentManager::fromConfig()` + the `'fake'` driver produce a real `Payment`.
2. **Idempotency** — the identical `charge()` call (same key, same payload) returns the *same*
   `providerReference` — no second charge happened.
3. **Partial refund.**
4. **Webhook dedup** — the same `event_id` delivered twice: `duplicate=no` then `duplicate=yes`,
   proving `Webhook\WebhookHandler`'s `EventStore::claim()`/`remember()` dedup works against the
   configured backend (not just in-memory).
5. **Stale webhook ignored** — a webhook reporting `captured` after the payment was already
   `refunded` is rejected by `Status\StateMachine` via the app-supplied `PaymentStatusLookup`,
   dispatching `WebhookIgnoredStale` instead of applying it.
6. **Reconciliation** — a payment stuck in `requires_action` is reconciled twice: no change, then
   (after a direct `capture()` simulates the redirect completing) a real `PaymentStatusChanged`.
7. **Pruner** (PDO only) — `Store\Pdo\Pruner::pruneAll()` removes a deliberately expired record,
   proving the housekeeping job a cron entry would run periodically actually works.

Both the SQLite and Redis paths have been run end-to-end against this exact script and confirmed
working (including a repeated run against the same backend, to confirm the walkthrough doesn't
silently collapse into idempotency replay on a second invocation).
