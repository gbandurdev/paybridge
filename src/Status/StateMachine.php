<?php

declare(strict_types=1);

namespace PayBridge\Status;

use PayBridge\Domain\PaymentStatus;

final class StateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending' => ['requires_action', 'authorized', 'captured', 'failed', 'expired'],
        'requires_action' => ['authorized', 'captured', 'failed', 'expired'],
        'authorized' => ['captured', 'voided', 'failed'],
        'captured' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['partially_refunded', 'refunded'],
        'refunded' => [],
        'voided' => [],
        'failed' => [],
        'expired' => [],
    ];

    public function canTransition(PaymentStatus $from, PaymentStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return \in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    public function isTerminal(PaymentStatus $status): bool
    {
        return self::TRANSITIONS[$status->value] === [];
    }
}
