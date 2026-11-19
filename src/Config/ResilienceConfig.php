<?php

declare(strict_types=1);

namespace PayBridge\Config;

/**
 * Parsed form of PaymentManager::fromConfig()'s 'resilience' array (docs/PLAN.md §5/§6.5),
 * applied uniformly to every registered gateway's middleware pipeline — PLAN.md's example shows
 * exactly one top-level 'resilience' block, not a per-gateway override.
 */
final readonly class ResilienceConfig
{
    private const array SUPPORTED_JITTER_STRATEGIES = ['full'];

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

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        /** @var array<string, mixed> $retry */
        $retry = \is_array($raw['retry'] ?? null) ? $raw['retry'] : [];
        /** @var array<string, mixed> $circuitBreaker */
        $circuitBreaker = \is_array($raw['circuit_breaker'] ?? null) ? $raw['circuit_breaker'] : [];

        $jitter = $retry['jitter'] ?? 'full';

        if (!\in_array($jitter, self::SUPPORTED_JITTER_STRATEGIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported retry.jitter strategy "%s"; only %s is implemented.',
                \is_string($jitter) ? $jitter : get_debug_type($jitter),
                implode(', ', self::SUPPORTED_JITTER_STRATEGIES),
            ));
        }

        return new self(
            timeoutMs: self::toInt($raw['timeout_ms'] ?? null, 10_000),
            retryMaxAttempts: self::toInt($retry['max_attempts'] ?? null, 4),
            retryBaseMs: self::toInt($retry['base_ms'] ?? null, 200),
            retryMaxMs: self::toInt($retry['max_ms'] ?? null, 5_000),
            circuitFailureThreshold: self::toInt($circuitBreaker['failure_threshold'] ?? null, 5),
            circuitCooldownSeconds: self::toInt($circuitBreaker['cooldown_s'] ?? null, 30),
            idempotencyLockTtlSeconds: self::toInt($raw['idempotency_lock_ttl_s'] ?? null, 30),
            idempotencyRecordTtlSeconds: self::toInt($raw['idempotency_record_ttl_s'] ?? null, 86_400),
        );
    }

    private static function toInt(mixed $value, int $default): int
    {
        return \is_int($value) ? $value : $default;
    }
}
