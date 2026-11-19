<?php

declare(strict_types=1);

namespace PayBridge\Resilience;

final class RealSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }
}
