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
}
