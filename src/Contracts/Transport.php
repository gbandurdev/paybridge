<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

/**
 * // DECISION: decoupled from PSR-18/PSR-7 at the contract level. The PSR-18 adapter
 * // (Http/Psr18Transport, Phase 2) translates to/from this shape, so Phase 1 doesn't need to
 * // add psr/http-client or psr/http-factory as dependencies before anything consumes them.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): TransportResponse;
}
