<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit;

use PayBridge\Contracts\Gateway;
use PayBridge\Domain\Capability;
use PayBridge\Domain\Money;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Domain\PaymentStatus;
use PayBridge\Drivers\Fake\FakeGateway;
use PayBridge\Exception\CardDeclined;
use PayBridge\Exception\CircuitOpen;
use PayBridge\Exception\NetworkException;
use PayBridge\Exception\ProviderUnavailable;
use PayBridge\Exception\RateLimitedException;
use PayBridge\PaymentManager;
use PHPUnit\Framework\TestCase;

/**
 * FallbackGateway::fallsThrough() is the behavior under test here, not the resilience pipeline
 * itself — retry/breaker config is kept minimal (max_attempts: 1) purely so each test runs
 * instantly, with no real sleeps.
 */
final class FallbackGatewayTest extends TestCase
{
    public function testCircuitOpenFallsThroughToNextGateway(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->throwingGateway(new CircuitOpen('open', gateway: 'primary', operation: 'charge')),
            'secondary' => fn() => $this->succeedingGateway(),
        ]);

        $payment = $manager->withFallback(['primary', 'secondary'])->charge($this->request('fb-circuit-open'));

        self::assertSame('pay_ok', $payment->providerReference);
    }

    public function testNonAmbiguousNetworkExceptionFallsThrough(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->throwingGateway(new NetworkException(
                'connection refused',
                gateway: 'primary',
                operation: 'charge',
                ambiguous: false,
            )),
            'secondary' => fn() => $this->succeedingGateway(),
        ]);

        $payment = $manager->withFallback(['primary', 'secondary'])->charge($this->request('fb-non-ambiguous'));

        self::assertSame('pay_ok', $payment->providerReference);
    }

    public function testAmbiguousNetworkExceptionRethrowsWithoutTryingNextGateway(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->throwingGateway(new NetworkException(
                'timed out',
                gateway: 'primary',
                operation: 'charge',
                ambiguous: true,
            )),
            'secondary' => fn() => $this->throwingGateway(new \LogicException('secondary must not be called')),
        ]);

        $this->expectException(NetworkException::class);

        $manager->withFallback(['primary', 'secondary'])->charge($this->request('fb-ambiguous'));
    }

    public function testRateLimitedExceptionRethrowsWithoutTryingNextGateway(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->throwingGateway(new RateLimitedException('rate limited', gateway: 'primary', operation: 'charge')),
            'secondary' => fn() => $this->throwingGateway(new \LogicException('secondary must not be called')),
        ]);

        $this->expectException(RateLimitedException::class);

        $manager->withFallback(['primary', 'secondary'])->charge($this->request('fb-rate-limited'));
    }

    public function testProviderUnavailableRethrowsWithoutTryingNextGateway(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->throwingGateway(new ProviderUnavailable('5xx', gateway: 'primary', operation: 'charge')),
            'secondary' => fn() => $this->throwingGateway(new \LogicException('secondary must not be called')),
        ]);

        $this->expectException(ProviderUnavailable::class);

        $manager->withFallback(['primary', 'secondary'])->charge($this->request('fb-provider-unavailable'));
    }

    public function testCardDeclinedRethrowsWithoutTryingNextGateway(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->throwingGateway(new CardDeclined('declined', gateway: 'primary', operation: 'charge')),
            'secondary' => fn() => $this->throwingGateway(new \LogicException('secondary must not be called')),
        ]);

        $this->expectException(CardDeclined::class);

        $manager->withFallback(['primary', 'secondary'])->charge($this->request('fb-card-declined'));
    }

    public function testNameDescribesTheConfiguredChain(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->succeedingGateway(),
            'secondary' => fn() => $this->succeedingGateway(),
        ]);

        self::assertSame('fallback:primary,secondary', $manager->withFallback(['primary', 'secondary'])->name());
    }

    public function testFindFallsThroughJustLikeCharge(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->throwingGateway(new CircuitOpen('open', gateway: 'primary', operation: 'find')),
            'secondary' => fn() => $this->succeedingGateway(),
        ]);

        $payment = $manager->withFallback(['primary', 'secondary'])->find('pay_1');

        self::assertSame('pay_1', $payment->providerReference);
    }

    public function testCapabilitiesDelegatesToTheFirstName(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => new FakeGateway(name: 'primary', capabilities: [Capability::REFUND]),
            'secondary' => fn() => new FakeGateway(name: 'secondary', capabilities: [Capability::VOID]),
        ]);

        self::assertSame([Capability::REFUND], $manager->withFallback(['primary', 'secondary'])->capabilities());
    }

    public function testEmptyNamesListThrows(): void
    {
        $manager = $this->managerWith(['primary' => fn() => $this->succeedingGateway()]);

        $this->expectException(\InvalidArgumentException::class);

        $manager->withFallback([]);
    }

    public function testAllNamesExhaustedWithFallthroughEligibleErrorsRethrowsTheLastOne(): void
    {
        $manager = $this->managerWith([
            'primary' => fn() => $this->throwingGateway(new CircuitOpen('open-primary', gateway: 'primary', operation: 'charge')),
            'secondary' => fn() => $this->throwingGateway(new CircuitOpen('open-secondary', gateway: 'secondary', operation: 'charge')),
        ]);

        try {
            $manager->withFallback(['primary', 'secondary'])->charge($this->request('fb-exhausted'));
            self::fail('Expected CircuitOpen was not thrown.');
        } catch (CircuitOpen $e) {
            self::assertSame('open-secondary', $e->getMessage());
        }
    }

    /**
     * @param array<string, callable(): Gateway> $factories
     */
    private function managerWith(array $factories): PaymentManager
    {
        $gateways = [];

        foreach (array_keys($factories) as $name) {
            $gateways[$name] = ['driver' => $name];
        }

        $manager = PaymentManager::fromConfig([
            'gateways' => $gateways,
            'resilience' => ['retry' => ['max_attempts' => 1]],
        ]);

        foreach ($factories as $name => $factory) {
            $manager->extend($name, $factory);
        }

        return $manager;
    }

    private function throwingGateway(\Throwable $exception): Gateway
    {
        return new class ($exception) implements Gateway {
            public function __construct(private readonly \Throwable $exception) {}

            public function name(): string
            {
                return 'throwing';
            }

            public function capabilities(): array
            {
                return [];
            }

            public function charge(PaymentRequest $request): Payment
            {
                throw $this->exception;
            }

            public function find(string $providerReference): Payment
            {
                throw $this->exception;
            }
        };
    }

    private function succeedingGateway(): Gateway
    {
        return new class implements Gateway {
            public function name(): string
            {
                return 'succeeding';
            }

            public function capabilities(): array
            {
                return [];
            }

            public function charge(PaymentRequest $request): Payment
            {
                return new Payment(
                    providerReference: 'pay_ok',
                    status: PaymentStatus::CAPTURED,
                    amount: $request->amount,
                    capturedAmount: $request->amount,
                );
            }

            public function find(string $providerReference): Payment
            {
                return new Payment(providerReference: $providerReference, status: PaymentStatus::CAPTURED, amount: Money::of('10.00', 'EUR'));
            }
        };
    }

    private function request(string $idempotencyKey): PaymentRequest
    {
        return new PaymentRequest(amount: Money::of('10.00', 'EUR'), idempotencyKey: $idempotencyKey);
    }
}
