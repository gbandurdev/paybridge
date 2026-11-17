<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ToolchainSmokeTest extends TestCase
{
    public function testAutoloaderAndToolchainAreWired(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/vendor/autoload.php');
    }
}
