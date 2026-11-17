<?php

declare(strict_types=1);

namespace PayBridge\Domain;

/**
 * An amount of money stored as integer minor units. Never use floats; parse decimal strings instead.
 */
final readonly class Money
{
    private function __construct(
        public int $minorUnits,
        public Currency $currency,
    ) {}

    /**
     * Parses a decimal string (e.g. "49.99") into minor units for the given currency.
     */
    public static function of(string $decimal, string|Currency $currency): self
    {
        $currency = \is_string($currency) ? Currency::of($currency) : $currency;

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $decimal, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid non-negative decimal amount.', $decimal));
        }

        $wholePart = $matches[1];
        $fractionPart = $matches[2] ?? '';

        if (\strlen($fractionPart) > $currency->exponent) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" has more fractional digits than %s allows (%d).',
                $decimal,
                $currency->code,
                $currency->exponent,
            ));
        }

        $fractionPart = str_pad($fractionPart, $currency->exponent, '0');
        $minorUnits = (int) ($wholePart . $fractionPart);

        return new self($minorUnits, $currency);
    }

    public static function ofMinor(int $minorUnits, string|Currency $currency): self
    {
        $currency = \is_string($currency) ? Currency::of($currency) : $currency;

        if ($minorUnits < 0) {
            throw new \InvalidArgumentException('Money cannot be negative.');
        }

        return new self($minorUnits, $currency);
    }

    public function toDecimalString(): string
    {
        if ($this->currency->exponent === 0) {
            return (string) $this->minorUnits;
        }

        $digits = str_pad((string) $this->minorUnits, $this->currency->exponent + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$this->currency->exponent) . '.' . substr($digits, -$this->currency->exponent);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::ofMinor($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot operate on %s and %s amounts together.',
                $this->currency->code,
                $other->currency->code,
            ));
        }
    }
}
