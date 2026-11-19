<?php

declare(strict_types=1);

namespace PayBridge\Exception;

final class IdempotencyConflict extends PaymentException
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $message,
        private readonly IdempotencyConflictReason $reason,
        ?string $gateway = null,
        ?string $operation = null,
        array $raw = [],
    ) {
        parent::__construct($message, $gateway, $operation, raw: $raw);
    }

    public function reason(): IdempotencyConflictReason
    {
        return $this->reason;
    }

    protected function dataForArray(): array
    {
        return [...parent::dataForArray(), 'reason' => $this->reason->value];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::toNonEmptyString($data['message'] ?? null),
            IdempotencyConflictReason::from(self::toNonEmptyString($data['reason'] ?? null)),
            self::toNullableString($data['gateway'] ?? null),
            self::toNullableString($data['operation'] ?? null),
            self::toArrayValue($data['raw'] ?? null),
        );
    }
}
