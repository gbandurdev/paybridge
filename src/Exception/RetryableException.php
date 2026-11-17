<?php

declare(strict_types=1);

namespace PayBridge\Exception;

/**
 * Marker for exceptions the resilience middleware is allowed to retry.
 */
interface RetryableException extends \Throwable {}
