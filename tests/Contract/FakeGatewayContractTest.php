<?php

declare(strict_types=1);

namespace PayBridge\Tests\Contract;

use PayBridge\Contracts\Gateway;
use PayBridge\Domain\Capability;
use PayBridge\Domain\Money;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Drivers\Fake\FakeGateway;
use PayBridge\Middleware\PipelineGateway;
use PayBridge\Testing\FakeClock;
use PayBridge\Testing\FakeSleeper;

/**
 * Proves the Phase 3 DoD: FakeGateway passes the contract suite through the full middleware
 * pipeline (every operation below runs through PipelineGateway, the same wrapper
 * PaymentManager will use for every real driver in Phase 4).
 */
final class FakeGatewayContractTest extends GatewayContractTestCase
{
    use PipelineTestMiddlewares;

    protected function createGateway(): Gateway
    {
        return $this->wrap(new FakeGateway());
    }

    protected function successfulChargeRequest(): PaymentRequest
    {
        return new PaymentRequest(Money::of('10.00', 'USD'), 'contract-charge');
    }

    protected function redirectTriggeringRequest(): PaymentRequest
    {
        return new PaymentRequest(Money::of('25.00', 'USD'), 'contract-redirect', metadata: ['fake_trigger' => 'redirect']);
    }

    protected function voidableAuthorizeRequest(): PaymentRequest
    {
        return new PaymentRequest(Money::of('15.00', 'USD'), 'contract-void-authorize');
    }

    protected function decliningRequest(): PaymentRequest
    {
        return new PaymentRequest(Money::of('5.00', 'USD'), 'contract-decline', metadata: ['fake_trigger' => 'decline']);
    }

    protected function validWebhookFixture(): WebhookFixture
    {
        $payload = self::webhookPayload();

        return new WebhookFixture($payload, ['X-Fake-Signature' => self::sign($payload)]);
    }

    protected function tamperedWebhookFixture(): WebhookFixture
    {
        $payload = self::webhookPayload();

        return new WebhookFixture($payload, ['X-Fake-Signature' => strrev(self::sign($payload))]);
    }

    protected function createGatewayWithoutCapability(Capability $capability): Gateway
    {
        $capabilities = array_values(array_filter(
            FakeGateway::DEFAULT_CAPABILITIES,
            static fn(Capability $c): bool => $c !== $capability,
        ));

        return $this->wrap(new FakeGateway(capabilities: $capabilities));
    }

    private function wrap(FakeGateway $gateway): PipelineGateway
    {
        $clock = new FakeClock();
        $sleeper = new FakeSleeper($clock);

        return new PipelineGateway($gateway, $this->buildMiddlewares($clock, $sleeper));
    }

    private static function webhookPayload(): string
    {
        return json_encode([
            'event_id' => 'evt_1',
            'type' => 'payment.captured',
            'reference' => 'fake_abc123',
            'status' => 'captured',
            'occurred_at' => '2026-01-01T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR);
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, FakeGateway::DEFAULT_WEBHOOK_SECRET);
    }
}
