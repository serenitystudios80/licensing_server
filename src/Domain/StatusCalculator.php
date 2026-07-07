<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * StatusCalculator - Pure function for license status transitions.
 *
 * Implements the active→grace→expired lifecycle for annual licenses:
 * - active → grace: When expires_at is reached
 * - grace → expired: 72 hours (259,200 seconds) after grace_start_at
 *
 * This is a PURE function with no side effects - it only computes what the
 * new status should be. Callers (Lazy_Check in /validate, Sweep_Job) are
 * responsible for persisting the computed result.
 *
 * Lifetime and revoked licenses are excluded by callers before calling this.
 *
 * Per Requirements 5.5, 5.6, 5.7, 10.2, 10.3, 11.1, 11.3, 11.4, 12.2, 12.3
 * and design.md StatusCalculator section.
 */
final class StatusCalculator
{
    /**
     * Grace period duration in seconds (72 hours = 3 days).
     */
    private const GRACE_PERIOD_SECONDS = 259200; // 72 hours * 3600 seconds

    /**
     * Compute the new status for a License.
     *
     * IMPORTANT: Callers must pre-filter out lifetime and revoked licenses.
     * This function assumes the license is annual and non-revoked.
     *
     * Logic:
     * 1. If status is 'active' and expires_at <= now:
     *    → Transition to 'grace', set grace_start_at = now
     * 
     * 2. If status is 'grace' and (now - grace_start_at) >= 259,200:
     *    → Transition to 'expired', keep grace_start_at unchanged
     *
     * 3. Otherwise: No change
     *
     * @param License $license The license to compute status for
     * @param int $now Current Unix timestamp
     * @return StatusComputation The computed result
     */
    public static function compute(License $license, int $now): StatusComputation
    {
        $currentStatus = $license->status;

        // Active → Grace transition
        if ($currentStatus === 'active' && self::shouldTransitionToGrace($license, $now)) {
            return new StatusComputation(
                status: 'grace',
                changed: true,
                graceStartTimestamp: $now,
            );
        }

        // Grace → Expired transition
        if ($currentStatus === 'grace' && self::shouldTransitionToExpired($license, $now)) {
            // Keep the original grace_start_at timestamp
            $graceStartTimestamp = $license->graceStartAt !== null
                ? strtotime($license->graceStartAt)
                : null;

            return new StatusComputation(
                status: 'expired',
                changed: true,
                graceStartTimestamp: $graceStartTimestamp,
            );
        }

        // No change needed
        return new StatusComputation(
            status: $currentStatus,
            changed: false,
            graceStartTimestamp: $license->graceStartAt !== null
                ? strtotime($license->graceStartAt)
                : null,
        );
    }

    /**
     * Check if a license should transition from active to grace.
     *
     * Condition: expires_at <= now
     */
    private static function shouldTransitionToGrace(License $license, int $now): bool
    {
        if ($license->expiresAt === null) {
            return false; // Lifetime licenses never expire
        }

        $expiresTimestamp = strtotime($license->expiresAt);
        return $expiresTimestamp <= $now;
    }

    /**
     * Check if a license should transition from grace to expired.
     *
     * Condition: (now - grace_start_at) >= 259,200 seconds (72 hours)
     */
    private static function shouldTransitionToExpired(License $license, int $now): bool
    {
        if ($license->graceStartAt === null) {
            return false; // No grace period started yet
        }

        $graceStartTimestamp = strtotime($license->graceStartAt);
        $graceElapsed = $now - $graceStartTimestamp;

        return $graceElapsed >= self::GRACE_PERIOD_SECONDS;
    }
}
