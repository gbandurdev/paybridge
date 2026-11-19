<?php

declare(strict_types=1);

namespace PayBridge\Reconciliation;

/**
 * One app-supplied payment reference to reconcile. PayBridge has no payment ledger of its own,
 * so the app is responsible for filtering candidates (e.g. "PENDING and older than N minutes")
 * from its own storage before handing them to Reconciler::reconcile().
 */
final readonly class ReconciliationCandidate
{
    public function __construct(
        public string $providerReference,
        public \DateTimeImmutable $pendingSince,
    ) {}
}
