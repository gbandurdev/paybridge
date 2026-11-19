<?php

declare(strict_types=1);

namespace PayBridge\Tests\Unit\Resilience;

use PayBridge\Resilience\SystemRandomSource;
use PHPUnit\Framework\TestCase;

final class SystemRandomSourceTest extends TestCase
{
    public function testReturnsValueWithinInclusiveRange(): void
    {
        $source = new SystemRandomSource();

        for ($i = 0; $i < 50; ++$i) {
            $value = $source->nextInt(10, 20);
            self::assertGreaterThanOrEqual(10, $value);
            self::assertLessThanOrEqual(20, $value);
        }
    }

    public function testMinEqualsMaxReturnsThatValue(): void
    {
        self::assertSame(7, (new SystemRandomSource())->nextInt(7, 7));
    }

    public function testMinGreaterThanMaxReturnsMinWithoutErroring(): void
    {
        self::assertSame(10, (new SystemRandomSource())->nextInt(10, 5));
    }
}
