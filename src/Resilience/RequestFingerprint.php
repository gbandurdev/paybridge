<?php

declare(strict_types=1);

namespace PayBridge\Resilience;

use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\Customer;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\RefundRequest;

/**
 * Deterministic hash of a mutating request's payload, excluding its idempotencyKey — used
 * by IdempotencyMiddleware to tell "same key, same payload" apart from "same key, different
 * payload". Field/insertion order never affects the result.
 */
final class RequestFingerprint
{
    public static function compute(PaymentRequest|RefundRequest|CaptureRequest $request): string
    {
        $data = match (true) {
            $request instanceof PaymentRequest => [
                'amount' => self::moneyToArray($request->amount),
                'orderId' => $request->orderId,
                'description' => $request->description,
                'returnUrl' => $request->returnUrl,
                'metadata' => $request->metadata,
                'customer' => $request->customer !== null ? self::customerToArray($request->customer) : null,
            ],
            $request instanceof RefundRequest => [
                'paymentReference' => $request->paymentReference,
                'amount' => $request->amount !== null ? self::moneyToArray($request->amount) : null,
                'reason' => $request->reason,
            ],
            $request instanceof CaptureRequest => [
                'paymentReference' => $request->paymentReference,
                'amount' => $request->amount !== null ? self::moneyToArray($request->amount) : null,
            ],
        };

        self::recursiveKsort($data);

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $json);
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    private static function moneyToArray(Money $money): array
    {
        return ['amount_minor' => $money->minorUnits, 'currency' => $money->currency->code];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customerToArray(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'email' => $customer->email,
            'name' => $customer->name,
            'metadata' => $customer->metadata,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function recursiveKsort(array &$data): void
    {
        ksort($data);

        foreach ($data as &$value) {
            if (\is_array($value)) {
                self::recursiveKsort($value);
            }
        }
    }
}
