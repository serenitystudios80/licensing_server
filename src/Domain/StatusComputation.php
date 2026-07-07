<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * StatusComputation result value object.
 *
 * Immutable result of StatusCalculator::compute(), representing the true
 * current status derived from timestamps and the current time.
 *
 * Used by both the Sweep_Job (hourly cron) and the Lazy_Check (/validate endpoint)
 * to ensure they always agree on status transitions (Correctness Property 10).
 *
 * Per design.md StatusCalculator section and Requirements 5.5, 5.6, 10.2, 11.1.
 */
final readonly class StatusComputation
{
    /**
     * @param string $status Computed status: 'active', 'grace', or 'expired'
     * @param bool $changed True if computed status differs from stored status
     * @param int|null $graceStartTimestamp Unix epoch timestamp to set as grace_start_at
     *                                       when transitioning active→grace for the first time.
     *                                       Null if no grace transition or grace-start already set.
     */
    public function __construct(
        public string $status,
        public bool $changed,
        public ?int $graceStartTimestamp,
    ) {
    }
}
