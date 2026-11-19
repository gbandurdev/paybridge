<?php

declare(strict_types=1);

namespace PayBridge\Event;

use PayBridge\Domain\PaymentEvent;
use PayBridge\Domain\PaymentStatus;

/**
 * Dispatched instead of PaymentStatusChanged when a PaymentStatusLookup is configured and the
 * incoming webhook event's status is not a legal Status\StateMachine transition from the app's
 * known status (e.g. a CAPTURED event arriving after the app already saw REFUNDED). The event
 * is still treated as processed — the provider gets a 200 and won't keep redelivering it.
 */
final readonly class WebhookIgnoredStale
{
    public function __construct(
        public PaymentEvent $event,
        public PaymentStatus $knownStatus,
    ) {}
}
