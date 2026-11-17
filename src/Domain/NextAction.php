<?php

declare(strict_types=1);

namespace PayBridge\Domain;

final readonly class NextAction
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public NextActionType $type,
        public ?string $url = null,
        public array $fields = [],
    ) {}
}
