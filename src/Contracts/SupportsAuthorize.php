<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

use PayBridge\Domain\Payment;
use PayBridge\Domain\PaymentRequest;

/**
 * For drivers that can authorize without capturing, as an alternative to {@see Gateway::charge()}'s
 * combined authorize+capture.
 */
interface SupportsAuthorize
{
    public function authorize(PaymentRequest $request): Payment;
}
