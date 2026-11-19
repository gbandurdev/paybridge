<?php

declare(strict_types=1);

namespace PayBridge\Exception;

/**
 * Round-trips a PaymentException through {@see PaymentException::toArray()}/fromArray()
 * so it can be stored as plain data (e.g. in an IdempotencyStore record) and reconstructed
 * later with its exact original class, not a generic PaymentException.
 */
final class ExceptionSerializer
{
    /**
     * @return array{class: string, data: array<string, mixed>}
     */
    public static function toArray(PaymentException $exception): array
    {
        return $exception->toArray();
    }

    /**
     * Refuses to instantiate anything that isn't a real PaymentException subtype — guards
     * against a stored record naming a class that was renamed/removed in a later library
     * version, or a tampered record once Redis/PDO-backed stores exist. Falls back to a
     * generic PaymentException built from whatever fields are present instead of crashing.
     *
     * @param array{class?: mixed, data?: mixed} $record
     */
    public static function fromArray(array $record): PaymentException
    {
        $class = $record['class'] ?? null;
        /** @var array<string, mixed> $data */
        $data = \is_array($record['data'] ?? null) ? $record['data'] : [];

        if (\is_string($class) && is_a($class, PaymentException::class, true)) {
            /** @var class-string<PaymentException> $class */
            return $class::fromArray($data);
        }

        return PaymentException::fromArray($data);
    }
}
