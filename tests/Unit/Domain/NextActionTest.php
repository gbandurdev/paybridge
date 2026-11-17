<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Domain;

use PayBridge\Domain\NextAction;
use PayBridge\Domain\NextActionType;
use PHPUnit\Framework\TestCase;

final class NextActionTest extends TestCase
{
    public function testDefaults(): void
    {
        $action = new NextAction(NextActionType::REDIRECT);

        self::assertSame(NextActionType::REDIRECT, $action->type);
        self::assertNull($action->url);
        self::assertSame([], $action->fields);
    }

    public function testAllFieldsSet(): void
    {
        $action = new NextAction(
            type: NextActionType::BANK_TRANSFER_INSTRUCTIONS,
            url: null,
            fields: ['iban' => 'DE00 0000 0000 0000 00'],
        );

        self::assertSame(NextActionType::BANK_TRANSFER_INSTRUCTIONS, $action->type);
        self::assertSame(['iban' => 'DE00 0000 0000 0000 00'], $action->fields);
    }

    public function testRedirectWithUrl(): void
    {
        $action = new NextAction(NextActionType::THREE_DS, url: 'https://pay.example/3ds');

        self::assertSame('https://pay.example/3ds', $action->url);
    }
}
