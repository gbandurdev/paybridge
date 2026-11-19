<?php

declare(strict_types=1);

namespace PayBridge\Tests\Contract;

final readonly class WebhookFixture
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $payload,
        public array $headers,
    ) {}
}
