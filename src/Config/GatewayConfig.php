<?php

declare(strict_types=1);

namespace PayBridge\Config;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Parsed form of one entry of PaymentManager::fromConfig()'s 'gateways' array
 * (docs/PLAN.md §5). $options is everything from that entry except 'driver' — handed as-is to
 * whatever factory is registered under $driver (the built-in 'fake' factory, or one supplied via
 * PaymentManager::extend()).
 */
final readonly class GatewayConfig
{
    private const NAME_PATTERN = '/^[a-z0-9_-]+$/';

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $name,
        public string $driver,
        public array $options,
        public ?LoggerInterface $logger = null,
        public ?ClientInterface $httpClient = null,
    ) {}

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(
        string $name,
        array $raw,
        ?LoggerInterface $logger = null,
        ?ClientInterface $httpClient = null,
    ): self {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Gateway name "%s" is invalid: it must match %s.',
                $name,
                self::NAME_PATTERN,
            ));
        }

        $driver = $raw['driver'] ?? null;

        if (!\is_string($driver) || trim($driver) === '') {
            throw new \InvalidArgumentException(sprintf('Gateway "%s" is missing a "driver" key.', $name));
        }

        unset($raw['driver']);

        return new self($name, $driver, $raw, $logger, $httpClient);
    }
}
