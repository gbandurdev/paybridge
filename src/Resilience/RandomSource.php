<?php

declare(strict_types=1);

namespace PayBridge\Resilience;

interface RandomSource
{
    /**
     * Returns an integer in the inclusive range [$min, $max].
     */
    public function nextInt(int $min, int $max): int;
}
