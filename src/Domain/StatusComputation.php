<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * StatusComputation - Result of StatusCalculator::compute().
 *
 * Immutable value object representing the computed status transition result.
 *
 * Per design.md StatusCalculator section.
 */
final readonly class StatusComputation
{
    public function __construct(
        /**
         * The computed status ('active', 'grace', or 'expired').
         */
        public string $status,

        /**
         * Whether the status changed from the input License.
         * True if a transition occurred, false if status stayed the same.
         */
        public bool $changed,

        /**
         * The grace_start_at timestamp (Unix timestamp).
         * - For active→grace: This is the current time ($now)
         * - For grace→expired: This preserves the original grace_start_at
         * - For no change: This preserves the existing grace_start_at (or null)
         */
        public ?int $graceStartTimestamp,
    ) {
    }
}
