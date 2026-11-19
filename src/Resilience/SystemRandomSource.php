<?php

declare(strict_types=1);

namespace PayBridge\Resilience;

final class SystemRandomSource implements RandomSource
{
    public function nextInt(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }

        return random_int($min, $max);
    }
}
