<?php

declare(strict_types=1);

namespace PayBridge\Resilience;

use PayBridge\Contracts\Clock;
use PayBridge\Exception\CircuitOpen;
use Psr\SimpleCache\CacheInterface;

/**
 * Per-gateway breaker state (closed -> open -> half-open -> closed), persisted in a
 * PSR-16 cache so it is shared across workers. Pure state machine: it has no opinion on
 * which exceptions count as failures — CircuitBreakerMiddleware decides that and calls
 * recordFailure()/recordSuccess() accordingly.
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Clock $clock,
        private readonly int $failureThreshold,
        private readonly int $cooldownSeconds,
    ) {}

    /**
     * Throws CircuitOpen if the breaker is open and the cooldown hasn't elapsed yet.
     * If the cooldown has elapsed, transitions to half-open and lets this call through
     * as a trial.
     */
    public function guard(string $gateway): void
    {
        $state = $this->load($gateway);

        if ($state['state'] !== self::STATE_OPEN) {
            return;
        }

        $openedAt = $state['openedAt'];

        if ($openedAt !== null && $this->clock->now()->getTimestamp() >= $openedAt + $this->cooldownSeconds) {
            $this->save($gateway, [
                'state' => self::STATE_HALF_OPEN,
                'consecutiveFailures' => $state['consecutiveFailures'],
                'openedAt' => $openedAt,
            ]);

            return;
        }

        throw new CircuitOpen(sprintf('Circuit breaker for "%s" is open.', $gateway), gateway: $gateway);
    }

    public function recordSuccess(string $gateway): void
    {
        $state = $this->load($gateway);

        if ($state['state'] === self::STATE_CLOSED && $state['consecutiveFailures'] === 0) {
            return;
        }

        $this->save($gateway, ['state' => self::STATE_CLOSED, 'consecutiveFailures' => 0, 'openedAt' => null]);
    }

    public function recordFailure(string $gateway): void
    {
        $state = $this->load($gateway);

        if ($state['state'] === self::STATE_HALF_OPEN) {
            $this->save($gateway, [
                'state' => self::STATE_OPEN,
                'consecutiveFailures' => $state['consecutiveFailures'] + 1,
                'openedAt' => $this->clock->now()->getTimestamp(),
            ]);

            return;
        }

        $consecutiveFailures = $state['consecutiveFailures'] + 1;

        if ($consecutiveFailures >= $this->failureThreshold) {
            $this->save($gateway, [
                'state' => self::STATE_OPEN,
                'consecutiveFailures' => $consecutiveFailures,
                'openedAt' => $this->clock->now()->getTimestamp(),
            ]);

            return;
        }

        $this->save($gateway, [
            'state' => self::STATE_CLOSED,
            'consecutiveFailures' => $consecutiveFailures,
            'openedAt' => null,
        ]);
    }

    /**
     * @return array{state: string, consecutiveFailures: int, openedAt: ?int}
     */
    private function load(string $gateway): array
    {
        /** @var array{state: string, consecutiveFailures: int, openedAt: ?int}|null $state */
        $state = $this->cache->get($this->cacheKey($gateway));

        return $state ?? ['state' => self::STATE_CLOSED, 'consecutiveFailures' => 0, 'openedAt' => null];
    }

    /**
     * @param array{state: string, consecutiveFailures: int, openedAt: ?int} $state
     */
    private function save(string $gateway, array $state): void
    {
        $this->cache->set($this->cacheKey($gateway), $state);
    }

    private function cacheKey(string $gateway): string
    {
        return sprintf('cb:%s', $gateway);
    }
}
