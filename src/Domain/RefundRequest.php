<?php

declare(strict_types=1);

namespace PayBridge\Domain;

use PayBridge\Exception\InvalidRequest;

final readonly class RefundRequest
{
    public function __construct(
        public string $paymentReference,
        public string $idempotencyKey,
        public ?Money $amount = null,
        public ?string $reason = null,
    ) {
        if (trim($idempotencyKey) === '') {
            throw new InvalidRequest('idempotencyKey is required.', operation: 'refund');
        }
    }
}
