<?php

declare(strict_types=1);

namespace PayBridge\Resilience;

interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
