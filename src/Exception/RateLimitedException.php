<?php

declare(strict_types=1);

namespace PayBridge\Exception;

final class RateLimitedException extends PaymentException implements RetryableException
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $message,
        private readonly ?int $retryAfterSeconds = null,
        ?string $gateway = null,
        ?string $operation = null,
        ?string $providerCode = null,
        ?int $httpStatus = null,
        array $raw = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $gateway, $operation, $providerCode, $httpStatus, true, $raw, $previous);
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
