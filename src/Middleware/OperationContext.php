<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

use PayBridge\Domain\Capability;
use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\RefundRequest;

/**
 * Shared, immutable value passed down the middleware pipeline for a single gateway
 * operation call. Middlewares attach data for inner stages via with*() rather than
 * mutating shared state.
 */
final readonly class OperationContext
{
    /**
     * @param list<Capability> $capabilities
     */
    public function __construct(
        public string $gateway,
        public string $operation,
        public PaymentRequest|RefundRequest|CaptureRequest|null $request,
        public array $capabilities,
        public ?int $timeoutMs = null,
    ) {}

    public function withTimeoutMs(int $timeoutMs): self
    {
        return new self($this->gateway, $this->operation, $this->request, $this->capabilities, $timeoutMs);
    }

    public function idempotencyKey(): ?string
    {
        return $this->request?->idempotencyKey;
    }

    public function hasCapability(Capability $capability): bool
    {
        return \in_array($capability, $this->capabilities, true);
    }
}
