<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

use PayBridge\Domain\PaymentEvent;

/**
 * // DECISION: takes the raw payload/headers rather than a dedicated WebhookRequest DTO, since
 * // Webhook/WebhookRequest.php belongs to Phase 4 (WebhookHandler) per docs/PLAN.md's directory
 * // layout — this avoids forward-depending on a type that doesn't exist yet.
 */
interface SupportsWebhooks
{
    /**
     * @param array<string, string> $headers
     */
    public function verifySignature(string $payload, array $headers): bool;

    /**
     * @param array<string, string> $headers
     */
    public function parseEvent(string $payload, array $headers): PaymentEvent;
}
