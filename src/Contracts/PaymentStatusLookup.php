<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

use PayBridge\Domain\PaymentStatus;

/**
 * App-supplied, optional lookup of "what does the app currently think this payment's status
 * is". PayBridge has no payment ledger of its own (see docs/PLAN.md's non-goals), so
 * WebhookHandler and Reconciler use this — when the app provides one — to detect stale/illegal
 * transitions via Status\StateMachine, instead of blindly trusting every incoming status.
 */
interface PaymentStatusLookup
{
    public function findStatus(string $gateway, string $providerReference): ?PaymentStatus;
}
