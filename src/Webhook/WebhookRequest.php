<?php

declare(strict_types=1);

namespace PayBridge\Webhook;

final readonly class WebhookRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $payload,
        public array $headers,
    ) {}
}
