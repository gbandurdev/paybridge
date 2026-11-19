<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Exception;

use PayBridge\Exception\AuthenticationFailed;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\CircuitOpen;
use PayBridge\Exception\ExceptionSerializer;
use PayBridge\Exception\IdempotencyConflict;
use PayBridge\Exception\IdempotencyConflictReason;
use PayBridge\Exception\InvalidRequest;
use PayBridge\Exception\InvalidSignature;
use PayBridge\Exception\NetworkException;
use PayBridge\Exception\PaymentException;
use PayBridge\Exception\ProviderUnavailable;
use PayBridge\Exception\RateLimitedException;
use PayBridge\Exception\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

final class ExceptionSerializerTest extends TestCase
{
    public function testCardDeclinedRoundTripsAsExactClass(): void
    {
        $original = new CardDeclined(
            'card declined',
            gateway: 'stripe',
            operation: 'charge',
            providerCode: 'insufficient_funds',
            httpStatus: 402,
            raw: ['foo' => 'bar'],
        );

        $reconstructed = ExceptionSerializer::fromArray(ExceptionSerializer::toArray($original));

        self::assertInstanceOf(CardDeclined::class, $reconstructed);
        self::assertSame('card declined', $reconstructed->getMessage());
        self::assertSame('stripe', $reconstructed->gateway());
        self::assertSame('charge', $reconstructed->operation());
        self::assertSame('insufficient_funds', $reconstructed->providerCode());
        self::assertSame(402, $reconstructed->httpStatus());
        self::assertFalse($reconstructed->retryable());
        self::assertSame(['foo' => 'bar'], $reconstructed->raw());
    }

    public function testAuthenticationFailedRoundTripsAsExactClass(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new AuthenticationFailed('bad key')),
        );

        self::assertInstanceOf(AuthenticationFailed::class, $reconstructed);
    }

    public function testUnsupportedOperationRoundTripsAsExactClass(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new UnsupportedOperation('not supported')),
        );

        self::assertInstanceOf(UnsupportedOperation::class, $reconstructed);
    }

    public function testInvalidSignatureRoundTripsAsExactClass(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new InvalidSignature('bad signature')),
        );

        self::assertInstanceOf(InvalidSignature::class, $reconstructed);
    }

    public function testInvalidRequestRoundTripsAsExactClass(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new InvalidRequest('missing field')),
        );

        self::assertInstanceOf(InvalidRequest::class, $reconstructed);
    }

    public function testCircuitOpenRoundTripsAsExactClass(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new CircuitOpen('breaker open', gateway: 'stripe')),
        );

        self::assertInstanceOf(CircuitOpen::class, $reconstructed);
        self::assertTrue($reconstructed->retryable());
    }

    public function testNetworkExceptionRoundTripsWithAmbiguousFlag(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new NetworkException('timed out', ambiguous: true, gateway: 'stripe')),
        );

        self::assertInstanceOf(NetworkException::class, $reconstructed);
        self::assertTrue($reconstructed->ambiguous());
        self::assertTrue($reconstructed->retryable());
    }

    public function testProviderUnavailableRoundTripsAsExactClass(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new ProviderUnavailable('503', httpStatus: 503)),
        );

        self::assertInstanceOf(ProviderUnavailable::class, $reconstructed);
        self::assertSame(503, $reconstructed->httpStatus());
    }

    public function testRateLimitedExceptionRoundTripsWithRetryAfter(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new RateLimitedException('429', retryAfterSeconds: 30)),
        );

        self::assertInstanceOf(RateLimitedException::class, $reconstructed);
        self::assertSame(30, $reconstructed->retryAfterSeconds());
    }

    public function testIdempotencyConflictRoundTripsWithReason(): void
    {
        $reconstructed = ExceptionSerializer::fromArray(
            ExceptionSerializer::toArray(new IdempotencyConflict(
                'payload mismatch',
                IdempotencyConflictReason::PAYLOAD_MISMATCH,
            )),
        );

        self::assertInstanceOf(IdempotencyConflict::class, $reconstructed);
        self::assertSame(IdempotencyConflictReason::PAYLOAD_MISMATCH, $reconstructed->reason());
    }

    public function testUnknownClassFallsBackToGenericPaymentExceptionInsteadOfCrashing(): void
    {
        $record = [
            'class' => 'SomeRenamedOrRemovedExceptionClass',
            'data' => ['message' => 'still readable', 'gateway' => 'stripe'],
        ];

        $reconstructed = ExceptionSerializer::fromArray($record);

        self::assertInstanceOf(PaymentException::class, $reconstructed);
        self::assertSame(PaymentException::class, $reconstructed::class);
        self::assertSame('still readable', $reconstructed->getMessage());
        self::assertSame('stripe', $reconstructed->gateway());
    }

    public function testNonPaymentExceptionClassIsRefused(): void
    {
        $record = [
            'class' => \RuntimeException::class,
            'data' => ['message' => 'not a PaymentException'],
        ];

        $reconstructed = ExceptionSerializer::fromArray($record);

        self::assertSame(PaymentException::class, $reconstructed::class);
    }

    public function testToArrayNeverUsesPhpSerialize(): void
    {
        $encoded = json_encode(ExceptionSerializer::toArray(new CardDeclined('declined')));

        self::assertIsString($encoded);
        self::assertStringNotContainsString('O:', $encoded);
    }
}
