<?php

declare(strict_types=1);

namespace PayBridge\Event;

use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentStatus;

/**
 * Dispatched by Webhook\WebhookHandler and Reconciliation\Reconciler whenever a
 * PaymentStatusLookup-supplied "known" status legally transitions (per Status\StateMachine) to a
 * freshly observed one. $previousStatus is null when the app genuinely didn't know this
 * reference's status yet (first sync). $payment is null from WebhookHandler (which never calls
 * find()) and always set from Reconciler (which does).
 */
final readonly class PaymentStatusChanged
{
    public function __construct(
        public string $gateway,
        public string $providerReference,
        public ?PaymentStatus $previousStatus,
        public PaymentStatus $newStatus,
        public ?Payment $payment = null,
    ) {}
}
