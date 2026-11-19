<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Middleware;

use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\SupportsRefund;
use PayBridge\Contracts\SupportsVoid;
use PayBridge\Contracts\SupportsWebhooks;
use PayBridge\Domain\Capability;
use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\Money;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentEvent;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Domain\Refund;
use PayBridge\Domain\RefundRequest;
use PayBridge\Exception\UnsupportedOperation;
use PayBridge\Middleware\Middleware;
use PayBridge\Middleware\OperationContext;
use PayBridge\Middleware\PipelineGateway;
use PHPUnit\Framework\TestCase;

final class PipelineGatewayTest extends TestCase
{
    public function testNameAndCapabilitiesDelegateToTheInnerGateway(): void
    {
        $inner = new BareGateway('stub', [Capability::REFUND]);
        $gateway = new PipelineGateway($inner, []);

        self::assertSame('stub', $gateway->name());
        self::assertSame([Capability::REFUND], $gateway->capabilities());
    }

    public function testChargeRoutesThroughTheGivenMiddlewaresAroundTheInner(): void
    {
        $inner = new BareGateway('stub', []);
        $tracer = new TracingMiddleware('A');
        $gateway = new PipelineGateway($inner, [$tracer]);

        $request = new PaymentRequest(Money::of('10.00', 'USD'), 'key');
        $payment = $gateway->charge($request);

        self::assertSame(['A-before', 'A-after'], $tracer->log);
        self::assertSame(PaymentStatus::CAPTURED, $payment->status);
        self::assertSame($request, $inner->lastChargeRequest);
    }

    public function testFindRoutesThroughTheMiddlewaresWithANullRequestInTheContext(): void
    {
        $inner = new BareGateway('stub', []);
        $capture = new ContextCapturingMiddleware();
        $gateway = new PipelineGateway($inner, [$capture]);

        $gateway->find('ref-1');

        self::assertNotNull($capture->context);
        self::assertNull($capture->context->request);
        self::assertSame('find', $capture->context->operation);
        self::assertSame('ref-1', $inner->lastFindReference);
    }

    public function testVoidRoutesThroughTheMiddlewaresWithANullRequestInTheContext(): void
    {
        $inner = new BareGateway('stub', [Capability::VOID]);
        $gateway = new PipelineGateway($inner, []);

        $payment = $gateway->void('ref-1');

        self::assertSame(PaymentStatus::VOIDED, $payment->status);
        self::assertSame('ref-1', $inner->lastVoidReference);
    }

    public function testRefundRoutesThroughThePipeline(): void
    {
        $inner = new BareGateway('stub', [Capability::REFUND]);
        $gateway = new PipelineGateway($inner, []);

        $refund = $gateway->refund(new RefundRequest('ref-1', 'refund-key'));

        self::assertSame('ref-1', $refund->paymentReference);
    }

    public function testVerifySignatureAndParseEventBypassTheMiddlewarePipeline(): void
    {
        $inner = new BareGateway('stub', [Capability::WEBHOOKS]);
        $tracer = new TracingMiddleware('A');
        $gateway = new PipelineGateway($inner, [$tracer]);

        self::assertTrue($gateway->verifySignature('payload', []));
        $gateway->parseEvent('payload', []);

        self::assertSame([], $tracer->log, 'Webhook methods must not touch the middleware pipeline.');
    }

    public function testOperationsRequiringAnUnimplementedSupportsInterfaceThrowUnsupportedOperation(): void
    {
        $inner = new GatewayOnlyStub('stub');
        $gateway = new PipelineGateway($inner, []);

        $this->assertThrowsUnsupportedOperation(fn() => $gateway->authorize(new PaymentRequest(Money::of('10.00', 'USD'), 'k')), 'authorize');
        $this->assertThrowsUnsupportedOperation(fn() => $gateway->capture(new CaptureRequest('ref', 'k')), 'capture');
        $this->assertThrowsUnsupportedOperation(fn() => $gateway->refund(new RefundRequest('ref', 'k')), 'refund');
        $this->assertThrowsUnsupportedOperation(fn() => $gateway->void('ref'), 'void');
        $this->assertThrowsUnsupportedOperation(fn() => $gateway->verifySignature('p', []), 'verifySignature');
        $this->assertThrowsUnsupportedOperation(fn() => $gateway->parseEvent('p', []), 'parseEvent');
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertThrowsUnsupportedOperation(callable $action, string $operation): void
    {
        try {
            $action();
            self::fail(sprintf('Expected UnsupportedOperation for "%s" but none was thrown.', $operation));
        } catch (UnsupportedOperation $e) {
            self::assertSame($operation, $e->operation());
        }
    }
}

/**
 * Records the before/after order it ran in, so pipeline wiring can be asserted without a
 * by-reference closure (which PHPStan can't see as "read" through the aliased variable).
 */
final class TracingMiddleware implements Middleware
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(private readonly string $name) {}

    public function process(OperationContext $context, callable $next): mixed
    {
        $this->log[] = "{$this->name}-before";
        $result = $next($context);
        $this->log[] = "{$this->name}-after";

        return $result;
    }
}

/**
 * Records the OperationContext it was called with, for asserting what PipelineGateway builds.
 */
final class ContextCapturingMiddleware implements Middleware
{
    public ?OperationContext $context = null;

    public function process(OperationContext $context, callable $next): mixed
    {
        $this->context = $context;

        return $next($context);
    }
}

/**
 * Minimal, fully-controlled Gateway test double for PipelineGateway's own unit tests — kept
 * separate from FakeGateway so this test suite exercises PipelineGateway in isolation.
 */
final class BareGateway implements Gateway, SupportsRefund, SupportsVoid, SupportsWebhooks
{
    public ?PaymentRequest $lastChargeRequest = null;

    public ?string $lastFindReference = null;

    public ?string $lastVoidReference = null;

    /**
     * @param list<Capability> $capabilities
     */
    public function __construct(
        private readonly string $name,
        private readonly array $capabilities,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function charge(PaymentRequest $request): Payment
    {
        $this->lastChargeRequest = $request;

        return new Payment('ref', PaymentStatus::CAPTURED, $request->amount, capturedAmount: $request->amount);
    }

    public function find(string $providerReference): Payment
    {
        $this->lastFindReference = $providerReference;

        return new Payment($providerReference, PaymentStatus::CAPTURED, Money::of('10.00', 'USD'), capturedAmount: Money::of('10.00', 'USD'));
    }

    public function refund(RefundRequest $request): Refund
    {
        return new Refund('rf_1', $request->paymentReference, PaymentStatus::REFUNDED, $request->amount ?? Money::of('10.00', 'USD'));
    }

    public function void(string $providerReference): Payment
    {
        $this->lastVoidReference = $providerReference;

        return new Payment($providerReference, PaymentStatus::VOIDED, Money::of('10.00', 'USD'));
    }

    public function verifySignature(string $payload, array $headers): bool
    {
        return true;
    }

    public function parseEvent(string $payload, array $headers): PaymentEvent
    {
        return new PaymentEvent($this->name, 'evt_1', 'test.event', new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }
}

/**
 * A Gateway implementing none of the Supports* interfaces, for proving PipelineGateway itself
 * rejects operations the inner driver genuinely can't do.
 */
final class GatewayOnlyStub implements Gateway
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function capabilities(): array
    {
        return [];
    }

    public function charge(PaymentRequest $request): Payment
    {
        return new Payment('ref', PaymentStatus::CAPTURED, $request->amount, capturedAmount: $request->amount);
    }

    public function find(string $providerReference): Payment
    {
        return new Payment($providerReference, PaymentStatus::CAPTURED, Money::of('10.00', 'USD'), capturedAmount: Money::of('10.00', 'USD'));
    }
}
