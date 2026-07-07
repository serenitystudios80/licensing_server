<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Injectable "current time" contract.
 *
 * `Clock` is injected wherever "current time" matters — HMAC timestamp
 * checks, rate limiting, `StatusCalculator`, admin session expiry, and
 * login lockout — so that time-dependent logic can be tested
 * deterministically without sleeping or mocking global functions like
 * `time()`.
 *
 * Production code depends on `SystemClock` (backed by the real system
 * clock); tests depend on `FixedClock` (a settable, deterministic clock).
 */
interface Clock
{
    /**
     * Returns the current time as a Unix epoch timestamp, in seconds.
     */
    public function now(): int;
}
