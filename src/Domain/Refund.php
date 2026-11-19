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

    /**
     * Normalized plain-array form, safe to store (e.g. in an IdempotencyStore record) and
     * later round-tripped through {@see self::fromArray()}. Never uses PHP's serialize().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'providerReference' => $this->providerReference,
            'paymentReference' => $this->paymentReference,
            'status' => $this->status->value,
            'amount' => ['amount_minor' => $this->amount->minorUnits, 'currency' => $this->amount->currency->code],
            'raw' => $this->raw,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var string $providerReference */
        $providerReference = $data['providerReference'];
        /** @var string $paymentReference */
        $paymentReference = $data['paymentReference'];
        /** @var string $status */
        $status = $data['status'];
        /** @var array{amount_minor: int, currency: string} $amount */
        $amount = $data['amount'];
        /** @var array<string, mixed> $raw */
        $raw = $data['raw'] ?? [];

        return new self(
            providerReference: $providerReference,
            paymentReference: $paymentReference,
            status: PaymentStatus::from($status),
            amount: Money::ofMinor($amount['amount_minor'], $amount['currency']),
            raw: $raw,
        );
    }
}
