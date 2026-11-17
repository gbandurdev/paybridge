<?php

declare(strict_types=1);

namespace PayBridge\Domain;

final readonly class Customer
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?string $id = null,
        public ?string $email = null,
        public ?string $name = null,
        public array $metadata = [],
    ) {}
}
