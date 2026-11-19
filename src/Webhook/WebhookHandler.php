<?php

declare(strict_types=1);

namespace PayBridge\Webhook;

use PayBridge\Contracts\EventStore;
use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\PaymentStatusLookup;
use PayBridge\Contracts\SupportsWebhooks;
use PayBridge\Domain\PaymentEvent;
use PayBridge\Event\PaymentStatusChanged;
use PayBridge\Event\WebhookIgnoredStale;
use PayBridge\Event\WebhookReceived;
use PayBridge\Exception\InvalidSignature;
use PayBridge\Exception\UnsupportedOperation;
use PayBridge\Status\StateMachine;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * docs/PLAN.md §3's webhook flow: verify signature -> parse -> dedup -> normalized PaymentEvent
 * -> PSR-14 dispatch. Deliberately does not run a "current status" check itself (§6.6 wants
 * stale transitions ignored, but PayBridge has no payment ledger of its own) — that only
 * happens when the app supplies a PaymentStatusLookup.
 *
 * Dedup uses a short "processing" claim (claimTtlSeconds, default 300s), only extended to the
 * long-term dedup window (dedupTtlSeconds, default 7 days per §6.5) once processing actually
 * succeeds. A worker that crashes mid-processing never reaches that extension, so the claim
 * simply expires after a few minutes instead of blocking every redelivery for a week.
 */
final class WebhookHandler
{
    public function __construct(
        /** @var \Closure(string): Gateway */
        private readonly \Closure $resolveGateway,
        private readonly EventStore $events,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?PaymentStatusLookup $statusLookup = null,
        private readonly int $claimTtlSeconds = 300,
        private readonly int $dedupTtlSeconds = 604_800,
    ) {}

    public function handle(string $gateway, WebhookRequest $request): PaymentEvent
    {
        $driver = ($this->resolveGateway)($gateway);

        if (!$driver instanceof SupportsWebhooks) {
            throw new UnsupportedOperation(
                sprintf('Gateway "%s" does not support webhooks.', $gateway),
                gateway: $gateway,
                operation: 'webhook',
            );
        }

        if (!$driver->verifySignature($request->payload, $request->headers)) {
            throw new InvalidSignature(
                sprintf('Webhook signature verification failed for gateway "%s".', $gateway),
                gateway: $gateway,
                operation: 'webhook',
            );
        }

        $event = $driver->parseEvent($request->payload, $request->headers);
        $dedupKey = sprintf('%s:%s', $gateway, $event->eventId);

        if (!$this->events->claim($dedupKey, $this->claimTtlSeconds)) {
            $this->dispatcher?->dispatch(new WebhookReceived($event, duplicate: true));

            return $event;
        }

        try {
            $this->applyStatusLookup($gateway, $event);
            $this->events->remember($dedupKey, $this->dedupTtlSeconds);
            $this->dispatcher?->dispatch(new WebhookReceived($event, duplicate: false));
        } catch (\Throwable $e) {
            $this->events->release($dedupKey);

            throw $e;
        }

        return $event;
    }

    private function applyStatusLookup(string $gateway, PaymentEvent $event): void
    {
        if ($this->statusLookup === null || $event->paymentReference === null || $event->status === null) {
            return;
        }

        $known = $this->statusLookup->findStatus($gateway, $event->paymentReference);

        if ($known === null) {
            return;
        }

        if ((new StateMachine())->canTransition($known, $event->status)) {
            $this->dispatcher?->dispatch(new PaymentStatusChanged(
                gateway: $gateway,
                providerReference: $event->paymentReference,
                previousStatus: $known,
                newStatus: $event->status,
            ));

            return;
        }

        $this->dispatcher?->dispatch(new WebhookIgnoredStale($event, $known));
    }
}
