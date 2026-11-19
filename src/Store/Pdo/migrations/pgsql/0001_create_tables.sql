CREATE TABLE IF NOT EXISTS paybridge_idempotency (
    id BIGSERIAL PRIMARY KEY,
    gateway VARCHAR(191) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    response TEXT NULL,
    error TEXT NULL,
    expires_at TIMESTAMP(6) NOT NULL,
    UNIQUE (gateway, idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_paybridge_idempotency_expires_at ON paybridge_idempotency (expires_at);

CREATE TABLE IF NOT EXISTS paybridge_locks (
    lock_key VARCHAR(191) PRIMARY KEY,
    token CHAR(32) NOT NULL,
    expires_at TIMESTAMP(6) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_paybridge_locks_expires_at ON paybridge_locks (expires_at);

CREATE TABLE IF NOT EXISTS paybridge_webhook_events (
    event_key VARCHAR(191) PRIMARY KEY,
    expires_at TIMESTAMP(6) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_paybridge_webhook_events_expires_at ON paybridge_webhook_events (expires_at);
