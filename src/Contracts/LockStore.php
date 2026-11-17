<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

interface LockStore
{
    public function acquire(string $key, int $ttlSeconds): bool;

    public function release(string $key): void;
}
