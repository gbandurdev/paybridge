<?php

declare(strict_types=1);

namespace PayBridge\Domain;

/**
 * Declares what an operation a {@see \PayBridge\Contracts\Gateway} driver supports.
 *
 * // DECISION: PLAN.md names these across multiple sections (§3, §6.2) without giving one
 * // authoritative list. This enum is the union of every capability named anywhere in the doc.
 */
enum Capability: string
{
    case AUTHORIZE = 'authorize';
    case CAPTURE = 'capture';
    case REFUND = 'refund';
    case REFUND_PARTIAL = 'refund_partial';
    case VOID = 'void';
    case REDIRECT_FLOW = 'redirect_flow';
    case WEBHOOKS = 'webhooks';
    case THREE_DS = 'three_ds';
    case PROVIDER_IDEMPOTENCY = 'provider_idempotency';
}
