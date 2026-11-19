<?php

declare(strict_types=1);

namespace PayBridge\Exception;

final class CardDeclined extends PaymentException
{
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
            self::toBool($data['retryable'] ?? null),
            self::toArrayValue($data['raw'] ?? null),
        );
    }
}
