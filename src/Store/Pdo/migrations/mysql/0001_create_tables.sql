CREATE TABLE IF NOT EXISTS paybridge_idempotency (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway VARCHAR(191) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL,
    response TEXT NULL,
    error TEXT NULL,
    expires_at DATETIME(6) NOT NULL,
    UNIQUE KEY uniq_paybridge_idempotency_gateway_key (gateway, idempotency_key),
    KEY idx_paybridge_idempotency_expires_at (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS paybridge_locks (
    lock_key VARCHAR(191) PRIMARY KEY,
    token CHAR(32) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    KEY idx_paybridge_locks_expires_at (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS paybridge_webhook_events (
    event_key VARCHAR(191) PRIMARY KEY,
    expires_at DATETIME(6) NOT NULL,
    KEY idx_paybridge_webhook_events_expires_at (expires_at)
) ENGINE=InnoDB;
