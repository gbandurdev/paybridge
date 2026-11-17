<?php

declare(strict_types=1);

namespace PayBridge\Domain;

final readonly class Refund
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $providerReference,
        public string $paymentReference,
        public PaymentStatus $status,
        public Money $amount,
        public array $raw = [],
    ) {}
}
