<?php

declare(strict_types=1);

namespace PayBridge\Event;

use PayBridge\Domain\PaymentEvent;

/**
 * Dispatched by Webhook\WebhookHandler for every inbound webhook, duplicate or not — a
 * subscriber uses $duplicate to skip side effects (e.g. crediting an order twice) while the
 * controller still always returns 200 regardless.
 */
final readonly class WebhookReceived
{
    public function __construct(
        public PaymentEvent $event,
        public bool $duplicate,
    ) {}
}
