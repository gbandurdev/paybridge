<?php

declare(strict_types=1);

namespace PayBridge\Store\Pdo;

use PayBridge\Contracts\Clock;
use PayBridge\Contracts\IdempotencyStore;

/**
 * PDO-backed IdempotencyStore (docs/PLAN.md §12): a (gateway, idempotency_key) unique
 * constraint + an expires_at index, exactly as the DoD mandates.
 *
 * IdempotencyMiddleware calls get()/put() with one composite string, "idem:{gateway}:{key}" —
 * this store splits it back apart (strip the "idem:" prefix, split on the first remaining ":")
 * to populate the two real columns. Safe in practice: gateway names are config-controlled and
 * validated against /^[a-z0-9_-]+$/ (Config\GatewayConfig), so they never contain ":".
 */
final class PdoIdempotencyStore implements IdempotencyStore
{
    use PdoSupport;

    private const string KEY_PREFIX = 'idem:';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function get(string $key): ?array
    {
        [$gateway, $idempotencyKey] = $this->parseKey($key);

        $stmt = $this->pdo->prepare(
            'SELECT fingerprint, status, response, error FROM paybridge_idempotency '
            . 'WHERE gateway = :gateway AND idempotency_key = :idempotency_key AND expires_at > :now',
        );
        $stmt->execute([
            'gateway' => $gateway,
            'idempotency_key' => $idempotencyKey,
            'now' => $this->formatTimestamp($this->clock->now()),
        ]);

        /** @var array{fingerprint: string, status: string, response: ?string, error: ?string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'fingerprint' => $row['fingerprint'],
            'status' => $row['status'],
            'response' => $this->decode($row['response']),
            'error' => $this->decode($row['error']),
        ];
    }

    public function put(string $key, array $record, int $ttlSeconds): void
    {
        [$gateway, $idempotencyKey] = $this->parseKey($key);

        $fingerprint = \is_string($record['fingerprint'] ?? null) ? $record['fingerprint'] : '';
        $status = \is_string($record['status'] ?? null) ? $record['status'] : '';
        $response = $this->encode($record['response'] ?? null);
        $error = $this->encode($record['error'] ?? null);
        $expiresAt = $this->formatTimestamp($this->clock->now()->modify(sprintf('+%d seconds', $ttlSeconds)));

        $params = [
            'gateway' => $gateway,
            'idempotency_key' => $idempotencyKey,
            'fingerprint' => $fingerprint,
            'status' => $status,
            'response' => $response,
            'error' => $error,
            'expires_at' => $expiresAt,
        ];

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO paybridge_idempotency (gateway, idempotency_key, fingerprint, status, response, error, expires_at) '
                . 'VALUES (:gateway, :idempotency_key, :fingerprint, :status, :response, :error, :expires_at)',
            );
            $stmt->execute($params);

            return;
        } catch (\PDOException $e) {
            if (!$this->isConstraintViolation($e)) {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE paybridge_idempotency SET fingerprint = :fingerprint, status = :status, response = :response, '
            . 'error = :error, expires_at = :expires_at WHERE gateway = :gateway AND idempotency_key = :idempotency_key',
        );
        $stmt->execute($params);
    }

    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM paybridge_idempotency WHERE expires_at <= :now');
        $stmt->execute(['now' => $this->formatTimestamp($this->clock->now())]);

        return $stmt->rowCount();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseKey(string $key): array
    {
        if (!str_starts_with($key, self::KEY_PREFIX)) {
            throw new \InvalidArgumentException(sprintf('Unexpected idempotency key format: "%s".', $key));
        }

        $rest = substr($key, \strlen(self::KEY_PREFIX));
        $separator = strpos($rest, ':');

        if ($separator === false) {
            throw new \InvalidArgumentException(sprintf('Unexpected idempotency key format: "%s".', $key));
        }

        return [substr($rest, 0, $separator), substr($rest, $separator + 1)];
    }

    private function encode(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
