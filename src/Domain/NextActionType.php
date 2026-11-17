<?php

declare(strict_types=1);

namespace PayBridge\Domain;

enum NextActionType: string
{
    case REDIRECT = 'redirect';
    case THREE_DS = 'three_ds';
    case BANK_TRANSFER_INSTRUCTIONS = 'bank_transfer_instructions';
}
