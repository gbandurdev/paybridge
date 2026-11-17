<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

interface IdempotencyStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $record
     */
    public function put(string $key, array $record, int $ttlSeconds): void;
}
