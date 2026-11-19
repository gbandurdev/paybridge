<?php

declare(strict_types=1);

namespace PayBridge;

use PayBridge\Contracts\Gateway;
use PayBridge\Domain\Capability;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentRequest;
use PayBridge\Exception\CircuitOpen;
use PayBridge\Exception\NetworkException;

/**
 * Returned by PaymentManager::withFallback(). Tries each configured gateway name in order,
 * falling through to the next one only for a CircuitOpen (the breaker never let the call reach
 * the provider at all) or a non-ambiguous NetworkException (provably never reached the provider,
 * e.g. connection refused before a byte was sent) — see NetworkException::ambiguous().
 *
 * Everything else rethrows immediately instead of trying another gateway: an ambiguous
 * NetworkException (a timeout — the provider may have already received and processed the
 * request), RateLimitedException and ProviderUnavailable (both mean the provider's servers did
 * receive the request), and any non-retryable failure (CardDeclined, InvalidRequest, ...) are
 * all real business outcomes against that specific provider. Falling through on any of those
 * risks charging the same order twice through two different processors.
 *
 * Only implements Gateway's core (name/capabilities/charge/find) — docs/PLAN.md §6.4's only
 * use-case is falling through after a breaker trip on the core charge/find path.
 */
final class FallbackGateway implements Gateway
{
    /**
     * @param list<string> $names
     */
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly array $names,
    ) {
        if ($this->names === []) {
            throw new \InvalidArgumentException('withFallback() needs at least one gateway name.');
        }
    }

    public function name(): string
    {
        return sprintf('fallback:%s', implode(',', $this->names));
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return $this->manager->gateway($this->names[0])->capabilities();
    }

    public function charge(PaymentRequest $request): Payment
    {
        return $this->attempt(static fn(Gateway $gateway): Payment => $gateway->charge($request));
    }

    public function find(string $providerReference): Payment
    {
        return $this->attempt(static fn(Gateway $gateway): Payment => $gateway->find($providerReference));
    }

    /**
     * @param callable(Gateway): Payment $operation
     */
    private function attempt(callable $operation): Payment
    {
        $last = null;

        foreach ($this->names as $name) {
            try {
                return $operation($this->manager->gateway($name));
            } catch (\Throwable $e) {
                if (!$this->fallsThrough($e)) {
                    throw $e;
                }

                $last = $e;
            }
        }

        throw $last;
    }

    private function fallsThrough(\Throwable $e): bool
    {
        return $e instanceof CircuitOpen
            || ($e instanceof NetworkException && !$e->ambiguous());
    }
}
