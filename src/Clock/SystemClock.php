<?php

declare(strict_types=1);

namespace PayBridge\Clock;

use PayBridge\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
