<?php

declare(strict_types=1);

namespace PayBridge\Exception;

final class ProviderUnavailable extends PaymentException implements RetryableException
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
