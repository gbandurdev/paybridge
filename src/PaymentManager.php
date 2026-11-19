<?php

declare(strict_types=1);

namespace PayBridge;

use PayBridge\Clock\SystemClock;
use PayBridge\Config\GatewayConfig;
use PayBridge\Config\ResilienceConfig;
use PayBridge\Contracts\Clock;
use PayBridge\Contracts\EventStore;
use PayBridge\Contracts\Gateway;
use PayBridge\Contracts\PaymentStatusLookup;
use PayBridge\Domain\Capability;
use PayBridge\Drivers\Fake\FakeGateway;
use PayBridge\Middleware\CircuitBreakerMiddleware;
use PayBridge\Middleware\IdempotencyMiddleware;
use PayBridge\Middleware\LoggingMiddleware;
use PayBridge\Middleware\Middleware;
use PayBridge\Middleware\MetricsMiddleware;
use PayBridge\Middleware\PipelineGateway;
use PayBridge\Middleware\RetryMiddleware;
use PayBridge\Middleware\TimeoutMiddleware;
use PayBridge\Reconciliation\Reconciler;
use PayBridge\Resilience\Backoff\ConstantBackoff;
use PayBridge\Resilience\Backoff\ExponentialJitterBackoff;
use PayBridge\Resilience\CircuitBreaker;
use PayBridge\Resilience\RealSleeper;
use PayBridge\Resilience\SystemRandomSource;
use PayBridge\Store\StoreFactory;
use PayBridge\Webhook\WebhookHandler;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * docs/PLAN.md §5/§12: one stable entry point built from a single config array. Wraps every
 * registered driver in Middleware\PipelineGateway (Phase 3) so real and custom drivers alike run
 * through the exact same resilience pipeline.
 */
final class PaymentManager
{
    /** @var array<string, GatewayConfig> */
    private readonly array $gatewayConfigs;

    /** @var array<string, callable(GatewayConfig): Gateway> */
    private array $registry;

    /** @var array<string, Gateway> */
    private array $resolved = [];

    /**
     * @param array<string, GatewayConfig> $gatewayConfigs
     * @param array<string, callable(GatewayConfig): Gateway> $registry
     * @param list<Middleware> $middlewares
     */
    private function __construct(
        array $gatewayConfigs,
        array $registry,
        private readonly array $middlewares,
        private readonly string $defaultName,
        private readonly EventStore $events,
        private readonly ?EventDispatcherInterface $dispatcher,
        private readonly ?PaymentStatusLookup $statusLookup,
        private readonly Clock $clock,
    ) {
        $this->gatewayConfigs = $gatewayConfigs;
        $this->registry = $registry;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        ?LoggerInterface $logger = null,
        ?ClientInterface $httpClient = null,
        ?EventDispatcherInterface $events = null,
        ?PaymentStatusLookup $statusLookup = null,
    ): self {
        $clock = new SystemClock();
        $sleeper = new RealSleeper();
        $random = new SystemRandomSource();
        $resilience = ResilienceConfig::fromArray(self::arrayOrEmpty($config['resilience'] ?? null));

        $storesConfig = self::arrayOrEmpty($config['stores'] ?? null);
        $idempotencyConfig = self::arrayOrEmpty($storesConfig['idempotency'] ?? null);
        $lockConfig = self::arrayOrEmpty($storesConfig['lock'] ?? null);
        $eventsConfig = self::arrayOrEmpty($storesConfig['events'] ?? null);
        $cacheConfig = self::arrayOrEmpty($storesConfig['cache'] ?? $idempotencyConfig);

        $factory = new StoreFactory($clock);
        $idempotencyStore = $factory->idempotency($idempotencyConfig);
        $lockStore = $factory->lock($lockConfig);
        $eventStore = $factory->events($eventsConfig);
        $cache = $factory->cache($cacheConfig);

        $breaker = new CircuitBreaker($cache, $clock, $resilience->circuitFailureThreshold, $resilience->circuitCooldownSeconds);

        $middlewares = [
            new LoggingMiddleware(),
            new MetricsMiddleware(),
            new IdempotencyMiddleware(
                $idempotencyStore,
                $lockStore,
                $sleeper,
                $resilience->idempotencyLockTtlSeconds,
                $resilience->idempotencyRecordTtlSeconds,
            ),
            new CircuitBreakerMiddleware($breaker),
            new RetryMiddleware(
                $sleeper,
                new ExponentialJitterBackoff($random),
                new ConstantBackoff(),
                $resilience->retryMaxAttempts,
                $resilience->retryBaseMs,
                $resilience->retryMaxMs,
            ),
            new TimeoutMiddleware($clock, $resilience->timeoutMs),
        ];

        $gatewayConfigs = [];

        foreach (self::arrayOrEmpty($config['gateways'] ?? null) as $name => $raw) {
            $gatewayConfigs[(string) $name] = GatewayConfig::fromArray((string) $name, self::arrayOrEmpty($raw), $logger, $httpClient);
        }

        $registry = [
            'fake' => static function (GatewayConfig $gatewayConfig): Gateway {
                $options = $gatewayConfig->options;
                /** @var list<Capability> $capabilities */
                $capabilities = \is_array($options['capabilities'] ?? null) ? $options['capabilities'] : FakeGateway::DEFAULT_CAPABILITIES;
                $webhookSecret = \is_string($options['webhook_secret'] ?? null) ? $options['webhook_secret'] : FakeGateway::DEFAULT_WEBHOOK_SECRET;

                return new FakeGateway(name: $gatewayConfig->name, capabilities: $capabilities, webhookSecret: $webhookSecret);
            },
        ];

        $defaultName = \is_string($config['default'] ?? null) ? $config['default'] : (array_key_first($gatewayConfigs) ?? '');

        return new self($gatewayConfigs, $registry, $middlewares, $defaultName, $eventStore, $events, $statusLookup, $clock);
    }

    /**
     * @param callable(GatewayConfig): Gateway $factory
     */
    public function extend(string $driver, callable $factory): static
    {
        $this->registry[$driver] = $factory;

        return $this;
    }

    public function gateway(string $name): Gateway
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $config = $this->gatewayConfigs[$name] ?? throw new \InvalidArgumentException(sprintf('No gateway configured under the name "%s".', $name));
        $factory = $this->registry[$config->driver] ?? throw new \InvalidArgumentException(sprintf(
            'No driver registered for "%s" (gateway "%s"). Register one with extend().',
            $config->driver,
            $name,
        ));

        return $this->resolved[$name] = new PipelineGateway($factory($config), $this->middlewares);
    }

    public function default(): Gateway
    {
        return $this->gateway($this->defaultName);
    }

    /**
     * @param list<string> $names
     */
    public function withFallback(array $names): Gateway
    {
        return new FallbackGateway($this, $names);
    }

    public function webhookHandler(): WebhookHandler
    {
        return new WebhookHandler($this->gateway(...), $this->events, $this->dispatcher, $this->statusLookup);
    }

    public function reconciler(): Reconciler
    {
        return new Reconciler($this->gateway(...), $this->clock, $this->dispatcher, $this->statusLookup);
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
