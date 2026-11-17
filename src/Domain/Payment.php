<?php

declare(strict_types=1);

namespace PayBridge\Domain;

final readonly class Payment
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $providerReference,
        public PaymentStatus $status,
        public Money $amount,
        public ?Money $capturedAmount = null,
        public ?Money $refundedAmount = null,
        public ?NextAction $nextAction = null,
        public array $raw = [],
    ) {}

    /**
     * How much of this payment can still be refunded: captured minus already refunded.
     */
    public function refundableAmount(): Money
    {
        $captured = $this->capturedAmount ?? Money::ofMinor(0, $this->amount->currency);
        $refunded = $this->refundedAmount ?? Money::ofMinor(0, $this->amount->currency);

        return $captured->subtract($refunded);
    }
}
