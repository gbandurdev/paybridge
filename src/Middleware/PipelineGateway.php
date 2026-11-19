<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\SupportsAuthorize;
use PayBridge\Contracts\SupportsCapture;
use PayBridge\Contracts\SupportsRedirect;
use PayBridge\Contracts\SupportsRefund;
use PayBridge\Contracts\SupportsVoid;
use PayBridge\Contracts\SupportsWebhooks;
use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentEvent;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\Refund;
use PayBridge\Domain\RefundRequest;
use PayBridge\Exception\UnsupportedOperation;

/**
 * Wraps any Gateway driver so every operation runs through the resilience middleware pipeline
 * (docs/PLAN.md §3: Logging -> Metrics -> Idempotency -> CircuitBreaker -> Retry -> Timeout ->
 * Driver). Reused as-is by PaymentManager in Phase 4 — this is the one place a driver gets
 * wrapped for production traffic, not a test-only convenience.
 *
 * // DECISION: structurally implements every Supports* interface regardless of what the
 * // wrapped driver supports, so callers can always type-check against this class. This means
 * // `$pipelineGateway instanceof SupportsRefund` (etc.) is *always* true once wrapped, even if
 * // the inner driver can't refund — capabilities() (which always delegates straight through to
 * // the inner driver) is the one signal this wrapper can't paper over, and is what callers
 * // (including the contract test suite) must gate on instead of instanceof.
 */
final class PipelineGateway implements
    Gateway,
    SupportsAuthorize,
    SupportsCapture,
    SupportsRefund,
    SupportsVoid,
    SupportsWebhooks,
    SupportsRedirect
{
    /**
     * @param list<Middleware> $middlewares
     */
    public function __construct(
        private readonly Gateway $inner,
        private readonly array $middlewares,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function capabilities(): array
    {
        return $this->inner->capabilities();
    }

    public function charge(PaymentRequest $request): Payment
    {
        $inner = $this->inner;

        /** @var Payment */
        return $this->run('charge', $request, static fn(OperationContext $ctx): Payment => $inner->charge(self::requireRequest($ctx)));
    }

    public function authorize(PaymentRequest $request): Payment
    {
        $this->assertInnerSupports(SupportsAuthorize::class, 'authorize');
        /** @var SupportsAuthorize&Gateway $inner */
        $inner = $this->inner;

        /** @var Payment */
        return $this->run('authorize', $request, static fn(OperationContext $ctx): Payment => $inner->authorize(self::requireRequest($ctx)));
    }

    public function capture(CaptureRequest $request): Payment
    {
        $this->assertInnerSupports(SupportsCapture::class, 'capture');
        /** @var SupportsCapture&Gateway $inner */
        $inner = $this->inner;

        /** @var Payment */
        return $this->run(
            'capture',
            $request,
            static fn(OperationContext $ctx): Payment => $inner->capture(self::requireCaptureRequest($ctx)),
        );
    }

    public function refund(RefundRequest $request): Refund
    {
        $this->assertInnerSupports(SupportsRefund::class, 'refund');
        /** @var SupportsRefund&Gateway $inner */
        $inner = $this->inner;

        /** @var Refund */
        return $this->run('refund', $request, static fn(OperationContext $ctx): Refund => $inner->refund(self::requireRefundRequest($ctx)));
    }

    public function void(string $providerReference): Payment
    {
        $this->assertInnerSupports(SupportsVoid::class, 'void');
        /** @var SupportsVoid&Gateway $inner */
        $inner = $this->inner;

        /** @var Payment */
        return $this->run('void', null, static fn(OperationContext $ctx): Payment => $inner->void($providerReference));
    }

    public function find(string $providerReference): Payment
    {
        $inner = $this->inner;

        /** @var Payment */
        return $this->run('find', null, static fn(OperationContext $ctx): Payment => $inner->find($providerReference));
    }

    public function verifySignature(string $payload, array $headers): bool
    {
        $this->assertInnerSupports(SupportsWebhooks::class, 'verifySignature');
        /** @var SupportsWebhooks&Gateway $inner */
        $inner = $this->inner;

        return $inner->verifySignature($payload, $headers);
    }

    public function parseEvent(string $payload, array $headers): PaymentEvent
    {
        $this->assertInnerSupports(SupportsWebhooks::class, 'parseEvent');
        /** @var SupportsWebhooks&Gateway $inner */
        $inner = $this->inner;

        return $inner->parseEvent($payload, $headers);
    }

    /**
     * @param class-string $interface
     */
    private function assertInnerSupports(string $interface, string $operation): void
    {
        if (!$this->inner instanceof $interface) {
            throw new UnsupportedOperation(
                sprintf('Gateway "%s" does not implement %s, required for "%s".', $this->inner->name(), $interface, $operation),
                gateway: $this->inner->name(),
                operation: $operation,
            );
        }
    }

    /**
     * @param callable(OperationContext): mixed $terminal
     */
    private function run(string $operation, PaymentRequest|RefundRequest|CaptureRequest|null $request, callable $terminal): mixed
    {
        $context = new OperationContext($this->inner->name(), $operation, $request, $this->inner->capabilities());

        return (new Pipeline($this->middlewares, $terminal))->run($context);
    }

    private static function requireRequest(OperationContext $context): PaymentRequest
    {
        \assert($context->request instanceof PaymentRequest);

        return $context->request;
    }

    private static function requireCaptureRequest(OperationContext $context): CaptureRequest
    {
        \assert($context->request instanceof CaptureRequest);

        return $context->request;
    }

    private static function requireRefundRequest(OperationContext $context): RefundRequest
    {
        \assert($context->request instanceof RefundRequest);

        return $context->request;
    }
}
