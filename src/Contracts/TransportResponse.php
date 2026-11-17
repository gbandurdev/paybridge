<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

final readonly class TransportResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
