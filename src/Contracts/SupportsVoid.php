<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

use PayBridge\Domain\Payment;

interface SupportsVoid
{
    public function void(string $providerReference): Payment;
}
