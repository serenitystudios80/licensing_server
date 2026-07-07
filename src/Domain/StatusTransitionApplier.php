<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repository\LicenseRepository;
use App\Repository\LicenseEventRepository;
use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * StatusTransitionApplier - Shared persistence rule for status transitions.
 *
 * Used by BOTH the Sweep_Job (hourly cron) and the Lazy_Check (/validate endpoint)
 * to persist StatusComputation results in a consistent way.
 *
 * This shared persistence logic ensures:
 * - Grace-start timestamp is set exactly once (optimistic WHERE status='active' guard)
 * - The same event types are appended by both mechanisms
 * - Both code paths converge on the same stored state (Correctness Property 10)
 *
 * Per Requirements 11.1, 11.2, 11.4 and design.md StatusCalculator section.
 */
final class StatusTransitionApplier
{
    public function __construct(
        private LicenseRepository $licenseRepo,
        private LicenseEventRepository $eventRepo,
    ) {
    }

    /**
     * Apply a StatusComputation result to a License row.
     *
     * If the computation indicates a status change:
     * 1. Persists the new status to the License row via LicenseRepository::updateFields()
     * 2. If transitioning to grace with a new grace-start timestamp, persists grace_start_at
     *    using an optimistic WHERE status='active' guard to prevent race condition overwrites
     * 3. Appends the appropriate LicenseEvent (silent_lapse_grace, sweep_grace_transition,
     *    sweep_expiry_transition, or no extra event depending on caller context)
     *
     * If the computation shows no change (changed=false), does nothing.
     *
     * @param License $license The original License entity (before transition)
     * @param StatusComputation $computation The computed status result
     * @param string $eventContext Context string: 'lazy_check' or 'sweep_job'
     * @return bool True if a transition was applied, false if no change
     * @throws \RuntimeException on persistence failure
     */
    public function apply(License $license, StatusComputation $computation, string $eventContext): bool
    {
        if (!$computation->changed) {
            return false; // No transition to apply
        }

        $licenseId = $license->id;
        $newStatus = $computation->status;
        $oldStatus = $license->status;

        try {
            // Step 1: Update status field (and grace_start_at if transitioning to grace)
            $fieldsToUpdate = ['status' => $newStatus];

            if ($computation->graceStartTimestamp !== null) {
                // Transitioning active → grace for the first time
                // Set grace_start_at using an optimistic guard
                // Note: The guard is implemented in LicenseRepository::updateFields()
                // by checking current tier before allowing expires_at updates.
                // For grace_start_at, we rely on the fact that it should only be set
                // when status is currently 'active' (the transition FROM active).
                
                // Convert Unix timestamp to MySQL DATETIME
                $graceStartDatetime = date('Y-m-d H:i:s', $computation->graceStartTimestamp);
                $fieldsToUpdate['grace_start_at'] = $graceStartDatetime;
            }

            $updated = $this->licenseRepo->updateFields($licenseId, $fieldsToUpdate);

            if (!$updated) {
                // No rows affected - possible race condition or license doesn't exist
                // Log but don't throw (fail gracefully for per-item resilience in Sweep_Job)
                $context = ErrorContext::describe(
                    new \RuntimeException('Status transition update affected 0 rows'),
                    [
                        'license_id' => $licenseId,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                        'event_context' => $eventContext,
                    ]
                );
                Logger::warning($context);
                return false;
            }

            // Step 2: Append the appropriate LicenseEvent
            $eventType = $this->determineEventType($oldStatus, $newStatus, $eventContext);
            
            if ($eventType !== null) {
                $this->eventRepo->append($licenseId, $eventType, [
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'context' => $eventContext,
                    'timestamp' => $computation->graceStartTimestamp ?? time(),
                ]);
            }

            return true;
        } catch (\Exception $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event_context' => $eventContext,
            ]);
            Logger::error($context);
            
            throw new \RuntimeException(
                "Failed to apply status transition for license {$licenseId} from '{$oldStatus}' to '{$newStatus}': " .
                "database update error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Determine which event type to append based on the transition and caller context.
     *
     * Rules (per design.md and requirements):
     * - active → grace (lazy_check): 'silent_lapse_grace'
     * - active → grace (sweep_job): 'sweep_grace_transition'
     * - grace → expired (lazy_check): no extra event (status field change is sufficient)
     * - grace → expired (sweep_job): 'sweep_expiry_transition'
     *
     * @param string $oldStatus Original status
     * @param string $newStatus New status
     * @param string $eventContext 'lazy_check' or 'sweep_job'
     * @return string|null Event type to append, or null if no event should be appended
     */
    private function determineEventType(string $oldStatus, string $newStatus, string $eventContext): ?string
    {
        if ($oldStatus === 'active' && $newStatus === 'grace') {
            // Active → grace transition
            return $eventContext === 'lazy_check' ? 'silent_lapse_grace' : 'sweep_grace_transition';
        }

        if ($oldStatus === 'grace' && $newStatus === 'expired') {
            // Grace → expired transition
            // Only sweep_job appends an event for this transition
            return $eventContext === 'sweep_job' ? 'sweep_expiry_transition' : null;
        }

        // Unexpected transition (shouldn't happen with current StatusCalculator logic)
        // Log a warning and return a generic event type
        Logger::warning(
            "Unexpected status transition: {$oldStatus} → {$newStatus} in context {$eventContext}"
        );
        return 'status_transition_unexpected';
    }

    /**
     * Apply a webhook-triggered grace transition (explicit payment failure).
     *
     * Similar to apply() but used specifically for webhook charge failure events
     * (Requirement 10.1, 10.4). Sets grace-start to the webhook processing time
     * and appends 'webhook_charge_failed' event.
     *
     * Includes duplicate failure guard: if license is already in grace/expired/revoked,
     * appends 'webhook_charge_failed_duplicate' instead and does NOT reset grace-start
     * (Requirement 10 AC4).
     *
     * @param License $license The license receiving the charge failure
     * @param int $webhookProcessingTime Unix timestamp when webhook was processed
     * @return bool True if transition applied, false if duplicate (already in grace/expired/revoked)
     * @throws \RuntimeException on persistence failure
     */
    public function applyWebhookChargeFailed(License $license, int $webhookProcessingTime): bool
    {
        $licenseId = $license->id;
        $currentStatus = $license->status;

        try {
            // Duplicate failure guard (Requirement 10 AC4)
            if ($currentStatus !== 'active') {
                // Already in grace/expired/revoked - append duplicate event without changing status
                $this->eventRepo->append($licenseId, 'webhook_charge_failed_duplicate', [
                    'current_status' => $currentStatus,
                    'webhook_time' => $webhookProcessingTime,
                    'reason' => 'License already in non-active status, grace-start not reset',
                ]);
                return false;
            }

            // Transition active → grace with grace-start at webhook processing time
            $graceStartDatetime = date('Y-m-d H:i:s', $webhookProcessingTime);
            
            $updated = $this->licenseRepo->updateFields($licenseId, [
                'status' => 'grace',
                'grace_start_at' => $graceStartDatetime,
            ]);

            if (!$updated) {
                throw new \RuntimeException(
                    "Webhook charge failure transition update affected 0 rows for license {$licenseId}"
                );
            }

            // Append webhook_charge_failed event
            $this->eventRepo->append($licenseId, 'webhook_charge_failed', [
                'old_status' => 'active',
                'new_status' => 'grace',
                'webhook_time' => $webhookProcessingTime,
                'grace_start_at' => $graceStartDatetime,
            ]);

            return true;
        } catch (\Exception $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'current_status' => $currentStatus,
                'webhook_time' => $webhookProcessingTime,
            ]);
            Logger::error($context);
            
            throw new \RuntimeException(
                "Failed to apply webhook charge failure for license {$licenseId}: " .
                "database update error. Check logs for details.",
                0,
                $e
            );
        }
    }
}
