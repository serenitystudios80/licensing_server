<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Real `Clock` implementation backed by PHP's system clock.
 *
 * This is the implementation wired into production code paths. It has
 * no state of its own — `now()` simply defers to `time()` on every call.
 */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
