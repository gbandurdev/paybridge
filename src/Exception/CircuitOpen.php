<?php

declare(strict_types=1);

namespace PayBridge\Exception;

/**
 * Thrown by CircuitBreakerMiddleware when the breaker is open; the inner driver is never
 * called. Implements RetryableException so IdempotencyMiddleware treats it like any other
 * transient failure (stores nothing, see IdempotencyMiddleware) rather than permanently
 * caching a breaker trip as a terminal failure for this idempotency key. This is safe for
 * RetryMiddleware too: CircuitBreakerMiddleware sits outside RetryMiddleware in the pipeline
 * and throws this before ever calling into it, so RetryMiddleware can never receive one.
 */
final class CircuitOpen extends PaymentException implements RetryableException
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $message,
        ?string $gateway = null,
        ?string $operation = null,
        ?string $providerCode = null,
        ?int $httpStatus = null,
        array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $gateway, $operation, $providerCode, $httpStatus, true, $raw, $previous);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::toNonEmptyString($data['message'] ?? null),
            self::toNullableString($data['gateway'] ?? null),
            self::toNullableString($data['operation'] ?? null),
            self::toNullableString($data['providerCode'] ?? null),
            self::toNullableInt($data['httpStatus'] ?? null),
            self::toArrayValue($data['raw'] ?? null),
        );
    }
}
