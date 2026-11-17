<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

use PayBridge\Domain\Refund;
use PayBridge\Domain\RefundRequest;

interface SupportsRefund
{
    public function refund(RefundRequest $request): Refund;
}
