<?php

declare(strict_types=1);

namespace PayBridge\Exception;

enum IdempotencyConflictReason: string
{
    case IN_PROGRESS = 'in_progress';
    case PAYLOAD_MISMATCH = 'payload_mismatch';
}
