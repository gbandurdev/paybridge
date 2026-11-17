<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

use PayBridge\Domain\Capability;
use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentRequest;

interface Gateway
{
    public function name(): string;

    /** @return list<Capability> */
    public function capabilities(): array;

    public function charge(PaymentRequest $request): Payment;

    public function find(string $providerReference): Payment;
}
