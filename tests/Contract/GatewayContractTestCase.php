<?php

declare(strict_types=1);

namespace PayBridge\Tests\Contract;

use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\SupportsAuthorize;
use PayBridge\Contracts\SupportsRefund;
use PayBridge\Contracts\SupportsVoid;
use PayBridge\Contracts\SupportsWebhooks;
use PayBridge\Domain\Capability;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Domain\RefundRequest;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\PaymentException;
use PayBridge\Exception\RetryableException;
use PayBridge\Exception\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

/**
 * Every Gateway driver must extend this and pass it (docs/PLAN.md §7.1). The base class
 * contains only assertions, never anything driver-specific — the abstract hooks below are the
 * entire reuse surface. See docs/plans/phase-3.md for the full design rationale, in particular
 * why scenario gating uses capabilities() (never instanceof Supports*): a PipelineGateway-wrapped
 * driver always structurally implements every Supports* interface regardless of what the
 * wrapped driver actually supports, so capabilities() is the one signal that can't be papered
 * over by the wrapper.
 */
abstract class GatewayContractTestCase extends TestCase
{
    /**
     * The gateway under test, already in its "happy path" configuration.
     */
    abstract protected function createGateway(): Gateway;

    /**
     * A request guaranteed to produce a successful charge() (CAPTURED or AUTHORIZED, never
     * REQUIRES_ACTION). Backs the charge/find/refund test groups — never null.
     */
    abstract protected function successfulChargeRequest(): PaymentRequest;

    /**
     * A request guaranteed to produce a REQUIRES_ACTION/NextAction result. Null only if the
     * gateway doesn't declare Capability::REDIRECT_FLOW — if it does, null fails the test.
     */
    abstract protected function redirectTriggeringRequest(): ?PaymentRequest;

    /**
     * A request that authorize() will leave in AUTHORIZED (not yet captured) status, ready to
     * void. Null only if the gateway doesn't declare Capability::VOID — if it does, null fails
     * the test.
     */
    abstract protected function voidableAuthorizeRequest(): ?PaymentRequest;

    /**
     * A request guaranteed to produce a provider decline, or null to skip (no Capability names
     * "can decline", so this is never subject to the declare-but-null failure rule).
     */
    abstract protected function decliningRequest(): ?PaymentRequest;

    /**
     * Valid-signature webhook fixture. Null only if the gateway doesn't declare
     * Capability::WEBHOOKS — if it does, null fails the test.
     */
    abstract protected function validWebhookFixture(): ?WebhookFixture;

    /**
     * Same payload as validWebhookFixture() with a signature that no longer matches it. Same
     * nullability rule as validWebhookFixture().
     */
    abstract protected function tamperedWebhookFixture(): ?WebhookFixture;

    /**
     * A gateway instance identical to createGateway() but missing exactly one capability, for
     * enforcement testing — or null if this driver can't/doesn't vary its capability set (a
     * real driver with a fixed capability set just returns null and that one enforcement case
     * is skipped for it; this is always a legitimate skip, never subject to the
     * declare-but-null failure rule, since it's about varying the capability set, not about
     * the currently-declared one).
     */
    abstract protected function createGatewayWithoutCapability(Capability $capability): ?Gateway;

    public function testChargeProducesASuccessfulPayment(): void
    {
        $gateway = $this->createGateway();
        $request = $this->successfulChargeRequest();
        $payment = $gateway->charge($request);

        self::assertNotSame('', $payment->providerReference);
        self::assertNotSame(
            PaymentStatus::REQUIRES_ACTION,
            $payment->status,
            'successfulChargeRequest() must resolve without requiring further action.',
        );
        self::assertTrue($payment->amount->equals($request->amount));
    }

    public function testFindReturnsConsistentState(): void
    {
        $gateway = $this->createGateway();
        $payment = $gateway->charge($this->successfulChargeRequest());

        $found = $gateway->find($payment->providerReference);

        self::assertSame($payment->providerReference, $found->providerReference);
        self::assertSame($payment->status, $found->status);
        self::assertTrue($payment->amount->equals($found->amount));
    }

    public function testFullRefund(): void
    {
        $gateway = $this->createGateway();

        if (!\in_array(Capability::REFUND, $gateway->capabilities(), true)) {
            self::markTestSkipped('Gateway does not declare Capability::REFUND.');
        }

        if (!$gateway instanceof SupportsRefund) {
            self::fail('Gateway declares Capability::REFUND but does not implement SupportsRefund.');
        }

        $payment = $gateway->charge($this->successfulChargeRequest());

        if ($payment->capturedAmount === null || $payment->capturedAmount->isZero()) {
            self::markTestSkipped('successfulChargeRequest() did not result in a captured payment; nothing to refund.');
        }

        $refundable = $payment->refundableAmount();
        $refund = $gateway->refund(new RefundRequest($payment->providerReference, 'contract-full-refund'));

        self::assertTrue($refund->amount->equals($refundable));

        $after = $gateway->find($payment->providerReference);
        self::assertTrue($after->refundableAmount()->isZero());
        self::assertSame(PaymentStatus::REFUNDED, $after->status);
    }

    public function testPartialRefundRespectsCapability(): void
    {
        $gateway = $this->createGateway();

        if (!\in_array(Capability::REFUND, $gateway->capabilities(), true)) {
            self::markTestSkipped('Gateway does not declare Capability::REFUND.');
        }

        if (!$gateway instanceof SupportsRefund) {
            self::fail('Gateway declares Capability::REFUND but does not implement SupportsRefund.');
        }

        $payment = $gateway->charge($this->successfulChargeRequest());

        if ($payment->capturedAmount === null || $payment->capturedAmount->isZero()) {
            self::markTestSkipped('successfulChargeRequest() did not result in a captured payment; nothing to partially refund.');
        }

        $half = Money::ofMinor(intdiv($payment->capturedAmount->minorUnits, 2), $payment->amount->currency);

        if ($half->isZero()) {
            self::markTestSkipped('Captured amount is too small to split for a partial refund.');
        }

        $request = new RefundRequest($payment->providerReference, 'contract-partial-refund', amount: $half);

        if (\in_array(Capability::REFUND_PARTIAL, $gateway->capabilities(), true)) {
            $refund = $gateway->refund($request);

            self::assertTrue($refund->amount->equals($half));
            self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, $gateway->find($payment->providerReference)->status);

            return;
        }

        $this->expectUnsupportedOperation(fn() => $gateway->refund($request), $gateway, 'refund');
    }

    public function testVoidRespectsCapability(): void
    {
        $gateway = $this->createGateway();

        if (!\in_array(Capability::VOID, $gateway->capabilities(), true)) {
            self::markTestSkipped('Gateway does not declare Capability::VOID.');
        }

        if (!$gateway instanceof SupportsVoid) {
            self::fail('Gateway declares Capability::VOID but does not implement SupportsVoid.');
        }

        $voidable = $this->voidableAuthorizeRequest();
        $this->assertCapabilityHookConsistency($voidable, Capability::VOID, $gateway, 'voidableAuthorizeRequest');

        if ($voidable === null) {
            self::markTestSkipped('voidableAuthorizeRequest() returned null; skipping void assertions.');
        }

        if (!$gateway instanceof SupportsAuthorize) {
            self::fail(
                'Gateway declares Capability::VOID and supplies voidableAuthorizeRequest() but does not '
                . 'implement SupportsAuthorize — there is no way to reach an AUTHORIZED state to void.',
            );
        }

        $payment = $gateway->authorize($voidable);
        $voided = $gateway->void($payment->providerReference);

        self::assertSame(PaymentStatus::VOIDED, $voided->status);
        self::assertSame(PaymentStatus::VOIDED, $gateway->find($payment->providerReference)->status);
    }

    public function testRedirectFlowWhenSupported(): void
    {
        $gateway = $this->createGateway();
        $request = $this->redirectTriggeringRequest();

        $this->assertCapabilityHookConsistency($request, Capability::REDIRECT_FLOW, $gateway, 'redirectTriggeringRequest');

        if ($request === null) {
            self::markTestSkipped('redirectTriggeringRequest() returned null; skipping redirect-flow assertions.');
        }

        $payment = $gateway->charge($request);

        self::assertSame(PaymentStatus::REQUIRES_ACTION, $payment->status);
        self::assertNotNull($payment->nextAction);
        self::assertNotSame('', $payment->nextAction->url ?? '');
    }

    public function testWebhookVerifyAndParseRoundTrip(): void
    {
        $gateway = $this->createGateway();
        $valid = $this->validWebhookFixture();
        $tampered = $this->tamperedWebhookFixture();

        $this->assertCapabilityHookConsistency($valid, Capability::WEBHOOKS, $gateway, 'validWebhookFixture');
        $this->assertCapabilityHookConsistency($tampered, Capability::WEBHOOKS, $gateway, 'tamperedWebhookFixture');

        if ($valid === null || $tampered === null) {
            self::markTestSkipped('Webhook fixtures unavailable; skipping webhook assertions.');
        }

        if (!$gateway instanceof SupportsWebhooks) {
            self::fail('Gateway declares Capability::WEBHOOKS but does not implement SupportsWebhooks.');
        }

        self::assertTrue($gateway->verifySignature($valid->payload, $valid->headers));

        $event = $gateway->parseEvent($valid->payload, $valid->headers);
        self::assertNotSame('', $event->eventId);
        self::assertNotSame('', $event->type);

        self::assertFalse($gateway->verifySignature($tampered->payload, $tampered->headers));
    }

    public function testDeclineIsMappedToCardDeclined(): void
    {
        $gateway = $this->createGateway();
        $request = $this->decliningRequest();

        if ($request === null) {
            self::markTestSkipped('decliningRequest() returned null; skipping decline assertions.');
        }

        try {
            $gateway->charge($request);
            self::fail('Expected CardDeclined but charge() succeeded.');
        } catch (CardDeclined $e) {
            $this->assertPaymentExceptionShape($e, $gateway, 'charge', retryable: false);
        }
    }

    public function testVoidCapabilityEnforcement(): void
    {
        $gateway = $this->createGateway();

        if (!\in_array(Capability::VOID, $gateway->capabilities(), true)) {
            self::markTestSkipped('Gateway does not declare Capability::VOID.');
        }

        $reduced = $this->createGatewayWithoutCapability(Capability::VOID);

        if ($reduced === null) {
            self::markTestSkipped('createGatewayWithoutCapability(VOID) returned null; this driver cannot vary its capability set.');
        }

        if (!$reduced instanceof SupportsVoid) {
            self::fail('Reduced-capability gateway no longer implements SupportsVoid; cannot exercise the enforcement path.');
        }

        $this->expectUnsupportedOperation(fn() => $reduced->void('contract-enforcement-reference'), $reduced, 'void');
    }

    public function testWebhookCapabilityEnforcement(): void
    {
        $gateway = $this->createGateway();

        if (!\in_array(Capability::WEBHOOKS, $gateway->capabilities(), true)) {
            self::markTestSkipped('Gateway does not declare Capability::WEBHOOKS.');
        }

        $reduced = $this->createGatewayWithoutCapability(Capability::WEBHOOKS);

        if ($reduced === null) {
            self::markTestSkipped('createGatewayWithoutCapability(WEBHOOKS) returned null; this driver cannot vary its capability set.');
        }

        if (!$reduced instanceof SupportsWebhooks) {
            self::fail('Reduced-capability gateway no longer implements SupportsWebhooks; cannot exercise the enforcement path.');
        }

        $this->expectUnsupportedOperation(fn() => $reduced->verifySignature('{}', []), $reduced, 'verifySignature');
    }

    public function testRedirectFlowCapabilityEnforcement(): void
    {
        $gateway = $this->createGateway();

        if (!\in_array(Capability::REDIRECT_FLOW, $gateway->capabilities(), true)) {
            self::markTestSkipped('Gateway does not declare Capability::REDIRECT_FLOW.');
        }

        $request = $this->redirectTriggeringRequest();

        if ($request === null) {
            self::fail('Gateway declares Capability::REDIRECT_FLOW but redirectTriggeringRequest() returned null.');
        }

        $reduced = $this->createGatewayWithoutCapability(Capability::REDIRECT_FLOW);

        if ($reduced === null) {
            self::markTestSkipped('createGatewayWithoutCapability(REDIRECT_FLOW) returned null; this driver cannot vary its capability set.');
        }

        $this->expectUnsupportedOperation(fn() => $reduced->charge($request), $reduced, 'charge');
    }

    /**
     * Fails if $gateway->capabilities() disagrees with whether $scenario is null — in either
     * direction. A declared capability with a null scenario hook, or an undeclared capability
     * with a non-null scenario hook, both mean the driver's capabilities() and its own test
     * fixtures have drifted out of sync.
     */
    private function assertCapabilityHookConsistency(mixed $scenario, Capability $capability, Gateway $gateway, string $hookName): void
    {
        $declares = \in_array($capability, $gateway->capabilities(), true);

        if ($declares && $scenario === null) {
            self::fail(sprintf(
                'Gateway "%s" declares %s but %s() returned null.',
                $gateway->name(),
                $capability->value,
                $hookName,
            ));
        }

        if (!$declares && $scenario !== null) {
            self::fail(sprintf(
                'Gateway "%s" does not declare %s but %s() returned a non-null scenario for it.',
                $gateway->name(),
                $capability->value,
                $hookName,
            ));
        }
    }

    /**
     * @param callable(): mixed $action
     */
    private function expectUnsupportedOperation(callable $action, Gateway $gateway, string $operation): void
    {
        try {
            $action();
            self::fail(sprintf('Expected UnsupportedOperation for "%s" but none was thrown.', $operation));
        } catch (UnsupportedOperation $e) {
            $this->assertPaymentExceptionShape($e, $gateway, $operation, retryable: false);
        }
    }

    private function assertPaymentExceptionShape(PaymentException $e, Gateway $gateway, string $operation, bool $retryable): void
    {
        self::assertSame($gateway->name(), $e->gateway());
        self::assertSame($operation, $e->operation());
        self::assertSame($retryable, $e->retryable());
        self::assertSame($retryable, $e instanceof RetryableException);
    }
}
