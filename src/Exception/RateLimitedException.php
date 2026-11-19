<?php

declare(strict_types=1);

namespace PayBridge\Exception;

final class RateLimitedException extends PaymentException implements RetryableException
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $message,
        private readonly ?int $retryAfterSeconds = null,
        ?string $gateway = null,
        ?string $operation = null,
        ?string $providerCode = null,
        ?int $httpStatus = null,
        array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $gateway, $operation, $providerCode, $httpStatus, true, $raw, $previous);
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }

    protected function dataForArray(): array
    {
        return [...parent::dataForArray(), 'retryAfterSeconds' => $this->retryAfterSeconds];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::toNonEmptyString($data['message'] ?? null),
            self::toNullableInt($data['retryAfterSeconds'] ?? null),
            self::toNullableString($data['gateway'] ?? null),
            self::toNullableString($data['operation'] ?? null),
            self::toNullableString($data['providerCode'] ?? null),
            self::toNullableInt($data['httpStatus'] ?? null),
            self::toArrayValue($data['raw'] ?? null),
        );
    }
}
