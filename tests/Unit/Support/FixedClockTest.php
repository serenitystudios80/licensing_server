<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Support\FixedClock
 */
final class FixedClockTest extends TestCase
{
    public function testNowReturnsTheInjectedFixedTimeDeterministically(): void
    {
        $clock = new FixedClock(1_700_000_000);

        self::assertSame(1_700_000_000, $clock->now());
        // Calling now() repeatedly must never drift or change on its own.
        self::assertSame(1_700_000_000, $clock->now());
        self::assertSame(1_700_000_000, $clock->now());
    }

    public function testSetUpdatesTheReturnedTime(): void
    {
        $clock = new FixedClock(1_700_000_000);

        $clock->set(1_800_000_000);

        self::assertSame(1_800_000_000, $clock->now());
    }

    public function testSetCanJumpBackwardsInTimeAsWellAsForwards(): void
    {
        $clock = new FixedClock(1_700_000_000);

        $clock->set(1_600_000_000);

        self::assertSame(1_600_000_000, $clock->now());
    }

    public function testAdvanceByMovesTheClockForward(): void
    {
        $clock = new FixedClock(1_700_000_000);

        $clock->advanceBy(3600);

        self::assertSame(1_700_003_600, $clock->now());
    }

    public function testAdvanceByWithANegativeValueMovesTheClockBackward(): void
    {
        $clock = new FixedClock(1_700_000_000);

        $clock->advanceBy(-3600);

        self::assertSame(1_699_996_400, $clock->now());
    }

    public function testAdvanceByCanBeCalledMultipleTimesCumulatively(): void
    {
        $clock = new FixedClock(0);

        $clock->advanceBy(100);
        $clock->advanceBy(50);
        $clock->advanceBy(-30);

        self::assertSame(120, $clock->now());
    }
}
