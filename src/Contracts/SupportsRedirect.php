<?php

declare(strict_types=1);

namespace PayBridge\Contracts;

/**
 * Marker for drivers that can return a {@see \PayBridge\Domain\NextAction} redirect from charge().
 *
 * // DECISION: no extra methods this phase — redirect completion goes through the existing
 * // capture()/find() flows, and docs/PLAN.md doesn't specify anything beyond the marker.
 */
interface SupportsRedirect {}
