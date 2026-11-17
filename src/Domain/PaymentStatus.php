<?php

declare(strict_types=1);

namespace PayBridge\Domain;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case REQUIRES_ACTION = 'requires_action';
    case AUTHORIZED = 'authorized';
    case CAPTURED = 'captured';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case REFUNDED = 'refunded';
    case VOIDED = 'voided';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
}
