<?php

declare(strict_types=1);

namespace PayBridge\Middleware;

use PayBridge\Contracts\IdempotencyStore;
use PayBridge\Contracts\LockStore;
use PayBridge\Domain\Payment;
use PayBridge\Domain\Refund;
use PayBridge\Exception\ExceptionSerializer;
use PayBridge\Exception\IdempotencyConflict;
use PayBridge\Exception\IdempotencyConflictReason;
use PayBridge\Exception\PaymentException;
use PayBridge\Exception\RetryableException;
use PayBridge\Resilience\RequestFingerprint;
use PayBridge\Resilience\Sleeper;

/**
 * Only wraps mutating operations whose request carries an idempotencyKey; find() (and
 * void(), which has no request DTO at all) have no key and bypass this middleware entirely.
 */
final class IdempotencyMiddleware implements Middleware
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly LockStore $lock,
        private readonly Sleeper $sleeper,
        private readonly int $lockTtlSeconds = 30,
        private readonly int $recordTtlSeconds = 86400,
        private readonly int $lockPollIntervalMs = 50,
    ) {}

    public function process(OperationContext $context, callable $next): mixed
    {
        $idempotencyKey = $context->idempotencyKey();

        if ($idempotencyKey === null) {
            return $next($context);
        }

        \assert($context->request !== null);

        $fingerprint = RequestFingerprint::compute($context->request);
        $key = sprintf('idem:%s:%s', $context->gateway, $idempotencyKey);
        $token = $this->acquireLock($key, $context);

        try {
            return $this->handle($context, $next, $key, $fingerprint);
        } finally {
            $this->lock->release($key, $token);
        }
    }

    private function handle(OperationContext $context, callable $next, string $key, string $fingerprint): mixed
    {
        $record = $this->store->get($key);

        if ($record !== null) {
            $storedFingerprint = $record['fingerprint'] ?? null;

            if (!\is_string($storedFingerprint) || !hash_equals($storedFingerprint, $fingerprint)) {
                throw new IdempotencyConflict(
                    'Idempotency key already used with a different request payload.',
                    IdempotencyConflictReason::PAYLOAD_MISMATCH,
                    gateway: $context->gateway,
                    operation: $context->operation,
                );
            }

            if (($record['status'] ?? null) === 'completed') {
                $response = $record['response'] ?? null;

                return $this->resultFromArray($context->operation, \is_array($response) ? $response : []);
            }

            $error = $record['error'] ?? null;

            throw ExceptionSerializer::fromArray(\is_array($error) ? $error : []);
        }

        try {
            $result = $next($context);
        } catch (\Throwable $e) {
            // A retryable failure (network blip, 503, breaker trip) is a verdict on the
            // provider's availability, not on this payload — caching it would force every
            // subsequent call with this key to replay today's transient outage for the
            // whole TTL. Only a non-retryable failure is truly terminal for this payload.
            if ($e instanceof PaymentException && !$e instanceof RetryableException) {
                $this->store->put($key, [
                    'fingerprint' => $fingerprint,
                    'status' => 'failed',
                    'error' => ExceptionSerializer::toArray($e),
                ], $this->recordTtlSeconds);
            }

            throw $e;
        }

        $this->store->put($key, [
            'fingerprint' => $fingerprint,
            'status' => 'completed',
            'response' => $this->resultToArray($result),
        ], $this->recordTtlSeconds);

        return $result;
    }

    private function acquireLock(string $key, OperationContext $context): string
    {
        $waitBudgetMs = $this->lockTtlSeconds * 1000;
        $waitedMs = 0;

        while (true) {
            $token = $this->lock->acquire($key, $this->lockTtlSeconds);

            if ($token !== null) {
                return $token;
            }

            if ($waitedMs >= $waitBudgetMs) {
                throw new IdempotencyConflict(
                    'Another request with this idempotency key is already in progress.',
                    IdempotencyConflictReason::IN_PROGRESS,
                    gateway: $context->gateway,
                    operation: $context->operation,
                );
            }

            $this->sleeper->sleep($this->lockPollIntervalMs);
            $waitedMs += $this->lockPollIntervalMs;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resultToArray(mixed $result): array
    {
        if ($result instanceof Refund || $result instanceof Payment) {
            return $result->toArray();
        }

        throw new \LogicException('An idempotency-wrapped operation must return a Payment or a Refund.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resultFromArray(string $operation, array $data): Payment|Refund
    {
        return $operation === 'refund' ? Refund::fromArray($data) : Payment::fromArray($data);
    }
}
