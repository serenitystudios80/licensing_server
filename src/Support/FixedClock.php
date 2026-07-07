<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deterministic, settable `Clock` implementation for tests.
 *
 * Construct with a starting epoch timestamp, then use `set()` to jump to
 * an arbitrary point in time (e.g. to simulate a grace period elapsing)
 * or `advanceBy()` to move forward by a number of seconds — without any
 * real waiting.
 */
final class FixedClock implements Clock
{
    private int $timestamp;

    public function __construct(int $timestamp)
    {
        $this->timestamp = $timestamp;
    }

    public function now(): int
    {
        return $this->timestamp;
    }

    /**
     * Sets the clock to an arbitrary epoch timestamp.
     */
    public function set(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    /**
     * Advances the clock by the given number of seconds (negative values
     * move the clock backward).
     */
    public function advanceBy(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
