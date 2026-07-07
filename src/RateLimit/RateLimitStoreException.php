<?php

declare(strict_types=1);

namespace App\RateLimit;

/**
 * RateLimitStoreException - Thrown when rate limit store operations fail.
 *
 * Used by RateLimitRepository to signal read failures (write failures are
 * caught and logged but never thrown).
 *
 * Per Requirements 2.3, 9.5 and design.md Rate limiting section.
 */
final class RateLimitStoreException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
