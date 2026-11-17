<?php

declare(strict_types=1);

namespace PayBridge\Domain;

final readonly class PaymentEvent
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $gateway,
        public string $eventId,
        public string $type,
        public \DateTimeImmutable $occurredAt,
        public ?string $paymentReference = null,
        public ?PaymentStatus $status = null,
        public array $raw = [],
    ) {}
}
