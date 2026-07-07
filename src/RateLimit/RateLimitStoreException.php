<?php

declare(strict_types=1);

namespace App\RateLimit;

/**
 * RateLimitStoreException - Thrown when rate limit store read fails.
 *
 * Distinct from write failures (which are logged and swallowed by record()).
 * Read failures (countSince()) throw this exception so the caller (RateLimiter)
 * can distinguish "0 requests" from "couldn't tell" and fail open correctly.
 *
 * Per Requirement 2 AC3, 9 AC8 and design.md RateLimiter section.
 */
final class RateLimitStoreException extends \RuntimeException
{
}
