<?php

declare(strict_types=1);

namespace PayBridge\Drivers\Fake;

use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\SupportsAuthorize;
use PayBridge\Contracts\SupportsCapture;
use PayBridge\Contracts\SupportsRedirect;
use PayBridge\Contracts\SupportsRefund;
use PayBridge\Contracts\SupportsVoid;
use PayBridge\Contracts\SupportsWebhooks;
use PayBridge\Domain\Capability;
use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\Money;
use PayBridge\Domain\NextAction;
use PayBridge\Domain\NextActionType;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentEvent;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Domain\Refund;
use PayBridge\Domain\RefundRequest;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\InvalidRequest;
use PayBridge\Exception\PaymentException;
use PayBridge\Exception\UnsupportedOperation;
use PayBridge\Status\StateMachine;

/**
 * Deterministic in-memory test double for Gateway + every Supports* interface. Which
 * operations actually behave as "supported" is controlled by the constructor's capability
 * list, not by which PHP interfaces this class implements — the same way a real driver must
 * enforce capabilities (docs/PLAN.md §3.2), and the thing that makes capability-enforcement
 * testing possible in the contract suite.
 *
 * // DECISION: no separate FakeStatusMap. Unlike a real driver, this class never receives a
 * // foreign provider status vocabulary to translate — its webhook payloads already speak
 * // PaymentStatus's own string values. A StatusMap exists specifically for that translation;
 * // there's nothing to translate here.
 */
final class FakeGateway implements
    Gateway,
    SupportsAuthorize,
    SupportsCapture,
    SupportsRefund,
    SupportsVoid,
    SupportsWebhooks,
    SupportsRedirect
{
    public const string DEFAULT_WEBHOOK_SECRET = 'fake-webhook-secret';

    /** @var list<Capability> */
    public const array DEFAULT_CAPABILITIES = [
        Capability::AUTHORIZE,
        Capability::CAPTURE,
        Capability::REFUND,
        Capability::REFUND_PARTIAL,
        Capability::VOID,
        Capability::REDIRECT_FLOW,
        Capability::WEBHOOKS,
    ];

    /** @var list<string> */
    private const array SCRIPTABLE_OPERATIONS = ['charge', 'find', 'refund', 'capture', 'void'];

    /** @var array<string, Payment> */
    private array $payments = [];

    /** @var array<string, list<array{class: class-string<PaymentException>, remaining: int}>> */
    private array $scriptedFailures = [];

    /**
     * @param list<Capability> $capabilities
     */
    public function __construct(
        private readonly string $name = 'fake',
        private readonly array $capabilities = self::DEFAULT_CAPABILITIES,
        private readonly string $webhookSecret = self::DEFAULT_WEBHOOK_SECRET,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @param class-string<PaymentException> $exceptionClass
     */
    public function willFail(string $operation, string $exceptionClass, int $times = 1): self
    {
        if (!\in_array($operation, self::SCRIPTABLE_OPERATIONS, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a scriptable FakeGateway operation.', $operation));
        }

        $this->scriptedFailures[$operation][] = ['class' => $exceptionClass, 'remaining' => $times];

        return $this;
    }

    /**
     * Readable no-op terminator: the scripted-failure queue draining to empty already means
     * "succeed next time." Returns void (not $this) so it reads as a chain terminator, not
     * something to keep chaining off of.
     */
    public function thenSucceed(): void {}

    public function charge(PaymentRequest $request): Payment
    {
        $this->consumeScriptedFailure('charge');

        if (($request->metadata['fake_trigger'] ?? null) === 'decline') {
            throw new CardDeclined(
                'The fake gateway declined this charge.',
                gateway: $this->name,
                operation: 'charge',
                providerCode: 'fake_decline',
            );
        }

        $reference = $this->newReference('fake');

        if (($request->metadata['fake_trigger'] ?? null) === 'redirect') {
            $this->requireCapability(Capability::REDIRECT_FLOW, 'charge');

            $payment = new Payment(
                providerReference: $reference,
                status: PaymentStatus::REQUIRES_ACTION,
                amount: $request->amount,
                nextAction: new NextAction(NextActionType::REDIRECT, sprintf('https://fake.test/approve/%s', $reference)),
            );
            $this->payments[$reference] = $payment;

            return $payment;
        }

        $payment = new Payment(
            providerReference: $reference,
            status: PaymentStatus::CAPTURED,
            amount: $request->amount,
            capturedAmount: $request->amount,
        );
        $this->payments[$reference] = $payment;

        return $payment;
    }

    public function authorize(PaymentRequest $request): Payment
    {
        $this->requireCapability(Capability::AUTHORIZE, 'authorize');

        if (($request->metadata['fake_trigger'] ?? null) === 'decline') {
            throw new CardDeclined(
                'The fake gateway declined this authorization.',
                gateway: $this->name,
                operation: 'authorize',
                providerCode: 'fake_decline',
            );
        }

        $reference = $this->newReference('fake');
        $payment = new Payment(providerReference: $reference, status: PaymentStatus::AUTHORIZED, amount: $request->amount);
        $this->payments[$reference] = $payment;

        return $payment;
    }

    public function capture(CaptureRequest $request): Payment
    {
        $this->requireCapability(Capability::CAPTURE, 'capture');
        $this->consumeScriptedFailure('capture');

        $payment = $this->lookup($request->paymentReference, 'capture');
        $this->assertTransition($payment->status, PaymentStatus::CAPTURED, 'capture');

        $capturedAmount = $request->amount ?? $payment->amount;
        $updated = new Payment(
            providerReference: $payment->providerReference,
            status: PaymentStatus::CAPTURED,
            amount: $payment->amount,
            capturedAmount: $capturedAmount,
            refundedAmount: $payment->refundedAmount,
            raw: $payment->raw,
        );
        $this->payments[$payment->providerReference] = $updated;

        return $updated;
    }

    public function refund(RefundRequest $request): Refund
    {
        $this->requireCapability(Capability::REFUND, 'refund');
        $this->consumeScriptedFailure('refund');

        $payment = $this->lookup($request->paymentReference, 'refund');
        $refundable = $payment->refundableAmount();
        $amount = $request->amount ?? $refundable;

        if ($amount->greaterThan($refundable)) {
            throw new InvalidRequest(
                sprintf('Refund amount exceeds the refundable balance for "%s".', $payment->providerReference),
                gateway: $this->name,
                operation: 'refund',
            );
        }

        if ($amount->lessThan($refundable)) {
            $this->requireCapability(Capability::REFUND_PARTIAL, 'refund');
        }

        $alreadyRefunded = $payment->refundedAmount ?? Money::ofMinor(0, $payment->amount->currency);
        $newRefundedAmount = $alreadyRefunded->add($amount);
        $capturedAmount = $payment->capturedAmount ?? $payment->amount;
        $newStatus = $newRefundedAmount->equals($capturedAmount) ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;

        $this->assertTransition($payment->status, $newStatus, 'refund');

        $updatedPayment = new Payment(
            providerReference: $payment->providerReference,
            status: $newStatus,
            amount: $payment->amount,
            capturedAmount: $payment->capturedAmount,
            refundedAmount: $newRefundedAmount,
            raw: $payment->raw,
        );
        $this->payments[$payment->providerReference] = $updatedPayment;

        return new Refund(
            providerReference: $this->newReference('fake_rf'),
            paymentReference: $payment->providerReference,
            status: $newStatus,
            amount: $amount,
        );
    }

    public function void(string $providerReference): Payment
    {
        $this->requireCapability(Capability::VOID, 'void');
        $this->consumeScriptedFailure('void');

        $payment = $this->lookup($providerReference, 'void');
        $this->assertTransition($payment->status, PaymentStatus::VOIDED, 'void');

        $updated = new Payment(
            providerReference: $payment->providerReference,
            status: PaymentStatus::VOIDED,
            amount: $payment->amount,
            capturedAmount: $payment->capturedAmount,
            refundedAmount: $payment->refundedAmount,
            raw: $payment->raw,
        );
        $this->payments[$payment->providerReference] = $updated;

        return $updated;
    }

    public function find(string $providerReference): Payment
    {
        $this->consumeScriptedFailure('find');

        return $this->lookup($providerReference, 'find');
    }

    public function verifySignature(string $payload, array $headers): bool
    {
        $this->requireCapability(Capability::WEBHOOKS, 'verifySignature');

        $signature = $headers['X-Fake-Signature'] ?? $headers['x-fake-signature'] ?? null;

        if (!\is_string($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $this->webhookSecret), $signature);
    }

    public function parseEvent(string $payload, array $headers): PaymentEvent
    {
        $this->requireCapability(Capability::WEBHOOKS, 'parseEvent');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        $eventId = $decoded['event_id'] ?? null;
        $type = $decoded['type'] ?? null;
        $occurredAt = $decoded['occurred_at'] ?? null;
        $reference = $decoded['reference'] ?? null;
        $status = $decoded['status'] ?? null;

        if (!\is_string($eventId) || !\is_string($type) || !\is_string($occurredAt)) {
            throw new InvalidRequest(
                'Fake webhook payload is missing one of "event_id", "type" or "occurred_at".',
                gateway: $this->name,
                operation: 'parseEvent',
            );
        }

        return new PaymentEvent(
            gateway: $this->name,
            eventId: $eventId,
            type: $type,
            occurredAt: new \DateTimeImmutable($occurredAt),
            paymentReference: \is_string($reference) ? $reference : null,
            status: \is_string($status) ? PaymentStatus::from($status) : null,
            raw: $decoded,
        );
    }

    private function requireCapability(Capability $capability, string $operation): void
    {
        if (!\in_array($capability, $this->capabilities, true)) {
            throw new UnsupportedOperation(
                sprintf('Gateway "%s" does not support capability "%s" for operation "%s".', $this->name, $capability->value, $operation),
                gateway: $this->name,
                operation: $operation,
            );
        }
    }

    private function lookup(string $providerReference, string $operation): Payment
    {
        return $this->payments[$providerReference] ?? throw new InvalidRequest(
            sprintf('No such payment reference "%s" on gateway "%s".', $providerReference, $this->name),
            gateway: $this->name,
            operation: $operation,
        );
    }

    private function assertTransition(PaymentStatus $from, PaymentStatus $to, string $operation): void
    {
        if (!(new StateMachine())->canTransition($from, $to)) {
            throw new InvalidRequest(
                sprintf('Cannot transition payment from "%s" to "%s" via "%s".', $from->value, $to->value, $operation),
                gateway: $this->name,
                operation: $operation,
            );
        }
    }

    private function consumeScriptedFailure(string $operation): void
    {
        $entry = $this->scriptedFailures[$operation][0] ?? null;

        if ($entry === null) {
            return;
        }

        $exceptionClass = $entry['class'];
        $remaining = $entry['remaining'] - 1;

        if ($remaining <= 0) {
            array_shift($this->scriptedFailures[$operation]);
        } else {
            $this->scriptedFailures[$operation][0]['remaining'] = $remaining;
        }

        throw new $exceptionClass('Scripted failure for the fake gateway.', gateway: $this->name, operation: $operation);
    }

    private function newReference(string $prefix): string
    {
        return sprintf('%s_%s', $prefix, bin2hex(random_bytes(8)));
    }
}
