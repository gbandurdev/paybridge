<?php

declare(strict_types=1);

namespace PayBridge\Domain;

use PayBridge\Exception\InvalidRequest;

final readonly class CaptureRequest
{
    public function __construct(
        public string $paymentReference,
        public string $idempotencyKey,
        public ?Money $amount = null,
    ) {
        if (trim($idempotencyKey) === '') {
            throw new InvalidRequest('idempotencyKey is required.', operation: 'capture');
        }
    }
}
