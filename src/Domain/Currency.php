<?php

declare(strict_types=1);

namespace PayBridge\Domain;

final readonly class Currency
{
    /** @var array<string, int> ISO 4217 code => minor-unit exponent, for codes that deviate from the default of 2. */
    private const EXPONENT_OVERRIDES = [
        // Zero-decimal currencies.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // Three-decimal currencies.
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    private const DEFAULT_EXPONENT = 2;

    private const CODE_PATTERN = '/^[A-Z]{3}$/';

    private function __construct(
        public string $code,
        public int $exponent,
    ) {}

    public static function of(string $isoCode): self
    {
        $code = strtoupper($isoCode);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid ISO 4217 currency code.', $isoCode));
        }

        return new self($code, self::EXPONENT_OVERRIDES[$code] ?? self::DEFAULT_EXPONENT);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
