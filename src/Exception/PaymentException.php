<?php

declare(strict_types=1);

namespace PayBridge\Exception;

class PaymentException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $message,
        private readonly ?string $gateway = null,
        private readonly ?string $operation = null,
        private readonly ?string $providerCode = null,
        private readonly ?int $httpStatus = null,
        private readonly bool $retryable = false,
        private readonly array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function gateway(): ?string
    {
        return $this->gateway;
    }

    public function operation(): ?string
    {
        return $this->operation;
    }

    public function providerCode(): ?string
    {
        return $this->providerCode;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Normalized array form, safe to store (e.g. in an IdempotencyStore record) and later
     * round-tripped through {@see self::fromArray()}. Never uses PHP's serialize().
     *
     * @return array{class: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'class' => static::class,
            'data' => $this->dataForArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataForArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'gateway' => $this->gateway,
            'operation' => $this->operation,
            'providerCode' => $this->providerCode,
            'httpStatus' => $this->httpStatus,
            'retryable' => $this->retryable,
            'raw' => $this->raw,
        ];
    }

    /**
     * Reconstructs a generic PaymentException from the `data` half of {@see self::toArray()}'s
     * output. This is the fallback used by {@see ExceptionSerializer} when the stored `class`
     * doesn't resolve to a real PaymentException subtype; subtypes that want to preserve their
     * own identity on replay (e.g. CardDeclined, NetworkException) define their own fromArray()
     * returning `self`, since this base implementation deliberately never uses `new static()`
     * (unsafe in a non-final class — a future subclass could add an incompatible constructor
     * without overriding fromArray) and always builds a plain PaymentException instead.
     *
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
            self::toBool($data['retryable'] ?? null),
            self::toArrayValue($data['raw'] ?? null),
        );
    }

    protected static function toNonEmptyString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    protected static function toNullableString(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    protected static function toNullableInt(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    protected static function toBool(mixed $value): bool
    {
        return \is_bool($value) && $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function toArrayValue(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
