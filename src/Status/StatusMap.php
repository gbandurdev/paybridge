<?php

declare(strict_types=1);

namespace PayBridge\Status;

use PayBridge\Domain\PaymentStatus;

/**
 * Maps a provider's own status string onto our normalized {@see PaymentStatus}. Implemented per driver.
 */
interface StatusMap
{
    public function map(string $providerStatus): PaymentStatus;
}
