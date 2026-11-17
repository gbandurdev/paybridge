<?php

declare(strict_types=1);

namespace PayBridge\Domain;

use PayBridge\Exception\InvalidRequest;

final readonly class PaymentRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public Money $amount,
        public string $idempotencyKey,
        public ?string $orderId = null,
        public ?string $description = null,
        public ?string $returnUrl = null,
        public array $metadata = [],
        public ?Customer $customer = null,
    ) {
        if (trim($idempotencyKey) === '') {
            throw new InvalidRequest('idempotencyKey is required.', operation: 'charge');
        }
    }
}
