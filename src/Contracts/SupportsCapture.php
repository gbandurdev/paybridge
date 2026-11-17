<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

use PayBridge\Domain\CaptureRequest;
use PayBridge\Domain\Payment;

interface SupportsCapture
{
    public function capture(CaptureRequest $request): Payment;
}
