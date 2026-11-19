<?php

declare(strict_types=1);

namespace PayBridge\Exception;

final class NetworkException extends PaymentException implements RetryableException
{
    /**
     * @param bool $ambiguous Whether the request may have already reached the provider
     *     (e.g. a timeout: we gave up waiting, but don't know if it was received). `false`
     *     means it's safe to assume nothing happened provider-side (e.g. connection refused
     *     before a byte was sent). Mutating-operation retries without provider idempotency
     *     support are blocked only for ambiguous failures — see RetryPolicy.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $message,
        ?string $gateway = null,
        ?string $operation = null,
        ?string $providerCode = null,
        ?int $httpStatus = null,
        private readonly bool $ambiguous = false,
        array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $gateway, $operation, $providerCode, $httpStatus, true, $raw, $previous);
    }

    public function ambiguous(): bool
    {
        return $this->ambiguous;
    }

    protected function dataForArray(): array
    {
        return [...parent::dataForArray(), 'ambiguous' => $this->ambiguous];
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
            self::toBool($data['ambiguous'] ?? null),
            self::toArrayValue($data['raw'] ?? null),
        );
    }
}
