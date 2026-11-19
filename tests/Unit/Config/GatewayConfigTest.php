<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Config;

use PayBridge\Config\GatewayConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GatewayConfigTest extends TestCase
{
    public function testFromArrayExtractsDriverAndKeepsRemainingOptions(): void
    {
        $config = GatewayConfig::fromArray('stripe', ['driver' => 'stripe', 'secret_key' => 'sk_test_x']);

        self::assertSame('stripe', $config->name);
        self::assertSame('stripe', $config->driver);
        self::assertSame(['secret_key' => 'sk_test_x'], $config->options);
    }

    public function testMissingDriverThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GatewayConfig::fromArray('stripe', []);
    }

    public function testBlankDriverThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GatewayConfig::fromArray('stripe', ['driver' => '   ']);
    }

    #[DataProvider('invalidNameProvider')]
    public function testInvalidGatewayNameThrows(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GatewayConfig::fromArray($name, ['driver' => 'fake']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'uppercase' => ['Stripe'];
        yield 'space' => ['my gateway'];
        yield 'slash' => ['stripe/eu'];
        yield 'empty' => [''];
    }

    #[DataProvider('validNameProvider')]
    public function testValidGatewayNamePasses(string $name): void
    {
        $config = GatewayConfig::fromArray($name, ['driver' => 'fake']);

        self::assertSame($name, $config->name);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validNameProvider(): iterable
    {
        yield 'lowercase' => ['stripe'];
        yield 'with-dash' => ['stripe-eu'];
        yield 'with-underscore' => ['stripe_eu'];
        yield 'with-digits' => ['gateway2'];
    }
}
