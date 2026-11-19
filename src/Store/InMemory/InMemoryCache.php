<?php

declare(strict_types=1);

namespace PayBridge\Store\InMemory;

use PayBridge\Contracts\Clock;
use Psr\SimpleCache\CacheInterface;

/**
 * Minimal PSR-16 implementation backing CircuitBreaker (and anything else needing a
 * shared-state TTL cache) for in-process testing. Uses the injected Clock for TTL math
 * instead of real wall-clock, so tests can fast-forward deterministically.
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: ?\DateTimeImmutable}> */
    private array $entries = [];

    public function __construct(private readonly Clock $clock) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->entries[$key]['value'];
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->entries[$key] = [
            'value' => $value,
            'expiresAt' => $this->expiryFor($ttl),
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return false;
        }

        if ($entry['expiresAt'] !== null && $this->clock->now() >= $entry['expiresAt']) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }

    private function expiryFor(null|int|\DateInterval $ttl): ?\DateTimeImmutable
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            return $this->clock->now()->add($ttl);
        }

        return $this->clock->now()->modify(sprintf('+%d seconds', $ttl));
    }
}
