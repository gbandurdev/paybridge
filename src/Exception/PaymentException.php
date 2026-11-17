<?php

declare(strict_types=1);

namespace PayBridge\Exception;

class PaymentException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $message,
        private readonly ?string $gateway = null,
        private readonly ?string $operation = null,
        private readonly ?string $providerCode = null,
        private readonly ?int $httpStatus = null,
        private readonly bool $retryable = false,
        private readonly array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function gateway(): ?string
    {
        return $this->gateway;
    }

    public function operation(): ?string
    {
        return $this->operation;
    }

    public function providerCode(): ?string
    {
        return $this->providerCode;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
