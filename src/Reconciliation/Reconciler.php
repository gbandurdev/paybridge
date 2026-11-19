<?php

declare(strict_types=1);

namespace PayBridge\Reconciliation;

use PayBridge\Contracts\Clock;
use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\PaymentStatusLookup;
use PayBridge\Domain\Payment;
use PayBridge\Event\PaymentStatusChanged;
use PayBridge\Status\StateMachine;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * docs/PLAN.md §12: finds PENDING/unknown payments and calls find() to sync their status,
 * meant to run from cron. PayBridge has no payment ledger of its own, so Reconciler never
 * queries anything itself — the app supplies an already-filtered iterable of candidates (e.g.
 * "PENDING and older than N minutes") from its own storage, and gets back the payments whose
 * status actually changed so it can persist them itself.
 */
final class Reconciler
{
    public function __construct(
        /** @var \Closure(string): Gateway */
        private readonly \Closure $resolveGateway,
        private readonly Clock $clock,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?PaymentStatusLookup $statusLookup = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param iterable<ReconciliationCandidate> $candidates
     * @return list<Payment>
     */
    public function reconcile(string $gateway, iterable $candidates, ?int $olderThanMinutes = null): array
    {
        $driver = ($this->resolveGateway)($gateway);
        $changed = [];

        foreach ($candidates as $candidate) {
            if ($olderThanMinutes !== null && $this->ageMinutes($candidate->pendingSince) < $olderThanMinutes) {
                continue;
            }

            try {
                $fresh = $driver->find($candidate->providerReference);
            } catch (\Throwable $e) {
                $this->logger?->warning('Reconciliation find() failed.', [
                    'gateway' => $gateway,
                    'reference' => $candidate->providerReference,
                    'exception' => $e,
                ]);

                continue;
            }

            $known = $this->statusLookup?->findStatus($gateway, $candidate->providerReference);

            if ($known !== null && $known === $fresh->status) {
                continue;
            }

            if ($known !== null && !(new StateMachine())->canTransition($known, $fresh->status)) {
                continue;
            }

            $this->dispatcher?->dispatch(new PaymentStatusChanged(
                gateway: $gateway,
                providerReference: $candidate->providerReference,
                previousStatus: $known,
                newStatus: $fresh->status,
                payment: $fresh,
            ));
            $changed[] = $fresh;
        }

        return $changed;
    }

    private function ageMinutes(\DateTimeImmutable $pendingSince): int
    {
        $seconds = $this->clock->now()->getTimestamp() - $pendingSince->getTimestamp();

        return \intdiv(max(0, $seconds), 60);
    }
}
