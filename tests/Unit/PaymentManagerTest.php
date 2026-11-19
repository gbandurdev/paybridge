<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit;

use PayBridge\Clock\SystemClock;
use PayBridge\Config\GatewayConfig;
use PayBridge\Contracts\Gateway;
use PayBridge\Domain\Money;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Drivers\Fake\FakeGateway;
use PayBridge\Exception\ProviderUnavailable;
use PayBridge\PaymentManager;
use PayBridge\Reconciliation\ReconciliationCandidate;
use PayBridge\Store\InMemory\InMemoryCache;
use PayBridge\Webhook\WebhookRequest;
use PHPUnit\Framework\TestCase;

final class PaymentManagerTest extends TestCase
{
    public function testFromConfigBuildsAWorkingFakeGateway(): void
    {
        $manager = PaymentManager::fromConfig([
            'gateways' => ['fake' => ['driver' => 'fake']],
        ]);

        $payment = $manager->gateway('fake')->charge($this->request('order-1'));

        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame(PaymentStatus::CAPTURED, $payment->status);
    }

    public function testDefaultResolvesTheConfiguredDefaultGatewayName(): void
    {
        $manager = PaymentManager::fromConfig([
            'default' => 'secondary',
            'gateways' => [
                'primary' => ['driver' => 'fake'],
                'secondary' => ['driver' => 'fake', 'webhook_secret' => 'only-secondary'],
            ],
        ]);

        self::assertSame($manager->gateway('secondary'), $manager->default());
    }

    public function testUnknownGatewayNameThrows(): void
    {
        $manager = PaymentManager::fromConfig(['gateways' => ['fake' => ['driver' => 'fake']]]);

        $this->expectException(\InvalidArgumentException::class);

        $manager->gateway('does-not-exist');
    }

    public function testUnknownDriverThrows(): void
    {
        $manager = PaymentManager::fromConfig(['gateways' => ['mystery' => ['driver' => 'no_such_driver']]]);

        $this->expectException(\InvalidArgumentException::class);

        $manager->gateway('mystery');
    }

    public function testExtendRegistersACustomDriverFactory(): void
    {
        $manager = PaymentManager::fromConfig(['gateways' => ['custom' => ['driver' => 'custom_driver', 'webhook_secret' => 'whsec_custom']]]);

        $manager->extend('custom_driver', static function (GatewayConfig $config): Gateway {
            self::assertSame('custom', $config->name);
            self::assertSame('whsec_custom', $config->options['webhook_secret']);

            return new FakeGateway(name: $config->name);
        });

        $payment = $manager->gateway('custom')->charge($this->request('order-custom'));

        self::assertInstanceOf(Payment::class, $payment);
    }

    public function testExtendCalledAfterConstructionStillAppliesBeforeFirstUse(): void
    {
        $manager = PaymentManager::fromConfig(['gateways' => ['custom' => ['driver' => 'custom_driver']]]);

        // extend() happens after fromConfig() already returned — must still be honored since
        // gateway() resolves lazily, not eagerly at fromConfig() time.
        $manager->extend('custom_driver', static fn(): Gateway => new FakeGateway(name: 'custom'));

        self::assertInstanceOf(Payment::class, $manager->gateway('custom')->charge($this->request('order-lazy')));
    }

    public function testGatewayResolutionIsMemoized(): void
    {
        $manager = PaymentManager::fromConfig(['gateways' => ['fake' => ['driver' => 'fake']]]);

        self::assertSame($manager->gateway('fake'), $manager->gateway('fake'));
    }

    public function testWebhookHandlerWiresTheConfiguredEventStoreForDedup(): void
    {
        $manager = PaymentManager::fromConfig(['gateways' => ['fake' => ['driver' => 'fake', 'webhook_secret' => 'whsec']]]);
        $handler = $manager->webhookHandler();

        $payload = json_encode(['event_id' => 'evt_1', 'type' => 'payment.updated', 'occurred_at' => '2026-01-01T00:00:01+00:00'], \JSON_THROW_ON_ERROR);
        $request = new WebhookRequest($payload, ['X-Fake-Signature' => hash_hmac('sha256', $payload, 'whsec')]);

        $first = $handler->handle('fake', $request);
        $second = $handler->handle('fake', $request);

        self::assertSame('evt_1', $first->eventId);
        self::assertSame('evt_1', $second->eventId);
    }

    public function testReconcilerResolvesTheSameRegisteredGateway(): void
    {
        $manager = PaymentManager::fromConfig(['gateways' => ['fake' => ['driver' => 'fake']]]);
        $payment = $manager->gateway('fake')->charge($this->request('order-reconcile'));

        $changed = $manager->reconciler()->reconcile('fake', [
            new ReconciliationCandidate($payment->providerReference, new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        ]);

        self::assertCount(1, $changed);
        self::assertSame($payment->providerReference, $changed[0]->providerReference);
    }

    public function testCircuitBreakerCacheDefaultsToTheIdempotencyStoreDriverWhenCacheKeyIsAbsent(): void
    {
        $cache = new InMemoryCache(new SystemClock());

        $manager = PaymentManager::fromConfig([
            'gateways' => ['flaky' => ['driver' => 'flaky']],
            'stores' => ['idempotency' => ['driver' => 'cache', 'cache' => $cache]],
            'resilience' => ['retry' => ['max_attempts' => 1], 'circuit_breaker' => ['failure_threshold' => 1]],
        ]);
        $manager->extend('flaky', static fn(): Gateway => new class implements Gateway {
            public function name(): string
            {
                return 'flaky';
            }

            public function capabilities(): array
            {
                return [];
            }

            public function charge(PaymentRequest $request): Payment
            {
                throw new ProviderUnavailable('down', gateway: 'flaky', operation: 'charge');
            }

            public function find(string $providerReference): Payment
            {
                throw new \LogicException('unused');
            }
        });

        try {
            $manager->gateway('flaky')->charge($this->request('order-flaky'));
        } catch (ProviderUnavailable) {
        }

        self::assertTrue($cache->has('cb:flaky'));
    }

    private function request(string $idempotencyKey): PaymentRequest
    {
        return new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: $idempotencyKey);
    }
}
