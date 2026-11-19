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

    /**
     * Normalized plain-array form, safe to store (e.g. in an IdempotencyStore record) and
     * later round-tripped through {@see self::fromArray()}. Never uses PHP's serialize(),
     * so the same shape works unchanged against Redis/PDO-backed stores later.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'providerReference' => $this->providerReference,
            'status' => $this->status->value,
            'amount' => self::moneyToArray($this->amount),
            'capturedAmount' => $this->capturedAmount !== null ? self::moneyToArray($this->capturedAmount) : null,
            'refundedAmount' => $this->refundedAmount !== null ? self::moneyToArray($this->refundedAmount) : null,
            'nextAction' => $this->nextAction !== null ? [
                'type' => $this->nextAction->type->value,
                'url' => $this->nextAction->url,
                'fields' => $this->nextAction->fields,
            ] : null,
            'raw' => $this->raw,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{amount_minor: int, currency: string} $amount */
        $amount = $data['amount'];
        /** @var array{amount_minor: int, currency: string}|null $captured */
        $captured = $data['capturedAmount'] ?? null;
        /** @var array{amount_minor: int, currency: string}|null $refunded */
        $refunded = $data['refundedAmount'] ?? null;
        /** @var array{type: string, url: ?string, fields: array<string, mixed>}|null $nextAction */
        $nextAction = $data['nextAction'] ?? null;
        /** @var string $providerReference */
        $providerReference = $data['providerReference'];
        /** @var string $status */
        $status = $data['status'];
        /** @var array<string, mixed> $raw */
        $raw = $data['raw'] ?? [];

        return new self(
            providerReference: $providerReference,
            status: PaymentStatus::from($status),
            amount: self::moneyFromArray($amount),
            capturedAmount: $captured !== null ? self::moneyFromArray($captured) : null,
            refundedAmount: $refunded !== null ? self::moneyFromArray($refunded) : null,
            nextAction: $nextAction !== null ? new NextAction(
                type: NextActionType::from($nextAction['type']),
                url: $nextAction['url'],
                fields: $nextAction['fields'],
            ) : null,
            raw: $raw,
        );
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    private static function moneyToArray(Money $money): array
    {
        return ['amount_minor' => $money->minorUnits, 'currency' => $money->currency->code];
    }

    /**
     * @param array{amount_minor: int, currency: string} $data
     */
    private static function moneyFromArray(array $data): Money
    {
        return Money::ofMinor($data['amount_minor'], $data['currency']);
    }
}
