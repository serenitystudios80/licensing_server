<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * StatusCalculator - Pure function for license status computation.
 *
 * This is the single most important shared component in the system.
 * It is a PURE FUNCTION with no side effects and no database access.
 *
 * Both the Sweep_Job (hourly cron) and the Lazy_Check (/validate endpoint)
 * call this exact same function with the same inputs, guaranteeing they can
 * never disagree about status transitions (Correctness Property 10).
 *
 * This design satisfies:
 * - Requirement 5 AC6: Lazy_Check never more favorable than Sweep_Job
 * - Requirement 11 AC2: grace-start timestamp never overwritten
 * - Requirements 10.2, 10.3, 11.1, 11.3, 11.4, 12.2, 12.3
 *
 * CRITICAL ASSUMPTIONS:
 * - Input License must be tier='annual' (callers pre-filter lifetime licenses)
 * - Input License must NOT be status='revoked' (callers pre-filter revoked licenses)
 * - The grace period is exactly 259,200 seconds (3 days)
 */
final class StatusCalculator
{
    /**
     * Grace period duration in seconds: 3 days = 259,200 seconds.
     * Per Requirements 10.2, 10.3.
     */
    private const GRACE_PERIOD_SECONDS = 259200; // 3 * 24 * 60 * 60

    /**
     * Compute the true current status for an annual, non-revoked license.
     *
     * Logic:
     * 1. If status='active' AND expires_at <= now → transition to 'grace'
     *    (silent lapse detection per Requirement 11.1)
     * 2. If status='grace' AND (now - grace_start_at) >= 259,200 → transition to 'expired'
     *    (grace expiry per Requirement 10.3)
     * 3. Otherwise, status remains unchanged
     *
     * Grace-start timestamp anchoring (Requirement 11 AC2):
     * - graceStartTimestamp is only set when transitioning FROM 'active' TO 'grace'
     * - If grace_start_at is already persisted in the License row, graceStartTimestamp is null
     * - This ensures the grace period countdown is anchored to a single stable point,
     *   regardless of which mechanism (Sweep_Job or Lazy_Check) first detects the lapse
     *
     * @param License $license The license to compute status for (must be tier='annual', status!='revoked')
     * @param int $now Current Unix epoch timestamp (seconds)
     * @return StatusComputation The computed status with metadata
     */
    public static function compute(License $license, int $now): StatusComputation
    {
        $currentStatus = $license->status;
        $expiresAt = $license->expiresAt;
        $graceStartAt = $license->graceStartAt;

        // CASE 1: Active license with past expires_at → transition to grace
        if ($currentStatus === 'active' && $expiresAt !== null) {
            $expiresAtTimestamp = strtotime($expiresAt);
            
            if ($expiresAtTimestamp !== false && $expiresAtTimestamp <= $now) {
                // Silent lapse detected: active → grace
                // Set grace-start to now (only if not already set)
                $graceStartTimestamp = ($graceStartAt === null) ? $now : null;
                
                return new StatusComputation(
                    status: 'grace',
                    changed: true,
                    graceStartTimestamp: $graceStartTimestamp,
                );
            }
        }

        // CASE 2: Grace license with elapsed grace period → transition to expired
        if ($currentStatus === 'grace' && $graceStartAt !== null) {
            $graceStartTimestamp = strtotime($graceStartAt);
            
            if ($graceStartTimestamp !== false) {
                $elapsedGraceSeconds = $now - $graceStartTimestamp;
                
                if ($elapsedGraceSeconds >= self::GRACE_PERIOD_SECONDS) {
                    // Grace period expired: grace → expired
                    return new StatusComputation(
                        status: 'expired',
                        changed: true,
                        graceStartTimestamp: null, // No grace-start change for grace→expired
                    );
                }
            }
        }

        // CASE 3: No transition - status remains as-is
        return new StatusComputation(
            status: $currentStatus,
            changed: false,
            graceStartTimestamp: null,
        );
    }

    /**
     * Check if a license should be excluded from status computation.
     *
     * Filters out lifetime and revoked licenses per Requirements 12.5, 12.6.
     * Both Sweep_Job and Lazy_Check must call this before compute().
     *
     * @param License $license The license to check
     * @return bool True if license should be excluded (lifetime or revoked)
     */
    public static function shouldExclude(License $license): bool
    {
        return $license->tier === 'lifetime' || $license->status === 'revoked';
    }

    /**
     * Get the grace period duration in seconds (for testing/documentation).
     *
     * @return int Grace period duration (259,200 seconds = 3 days)
     */
    public static function getGracePeriodSeconds(): int
    {
        return self::GRACE_PERIOD_SECONDS;
    }
}
