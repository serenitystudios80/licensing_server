<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repository\LicenseRepository;
use App\Repository\LicenseEventRepository;
use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * StatusTransitionApplier - Persist StatusComputation results.
 *
 * Shared helper used by both Lazy_Check (/validate) and Sweep_Job to apply
 * StatusCalculator results to the database.
 *
 * Responsibilities:
 * - Update license status and grace_start_at via LicenseRepository
 * - Use optimistic WHERE status = 'active' guard when setting grace-start
 * - Append appropriate event (silent_lapse_grace or sweep_grace_transition)
 * - No event for grace→expired (expiration is silent in the event log)
 *
 * Per Requirements 11.1, 11.2, 11.4 and design.md StatusCalculator section.
 */
final class StatusTransitionApplier
{
    private LicenseRepository $licenseRepo;
    private LicenseEventRepository $eventRepo;

    public function __construct(
        LicenseRepository $licenseRepo,
        LicenseEventRepository $eventRepo,
    ) {
        $this->licenseRepo = $licenseRepo;
        $this->eventRepo = $eventRepo;
    }

    /**
     * Apply a StatusComputation result to a License.
     *
     * If the status changed:
     * - Updates the license status and grace_start_at
     * - Uses optimistic locking for active→grace (WHERE status = 'active')
     * - Appends the appropriate event based on $eventType
     *
     * If status unchanged: No-op (returns immediately).
     *
     * @param int $licenseId The license ID to update
     * @param StatusComputation $computation The computed status result
     * @param string $eventType Event type to append ('silent_lapse_grace', 'sweep_grace_transition', etc.)
     * @return bool True if update succeeded, false if optimistic lock failed
     * @throws \RuntimeException on database error
     */
    public function apply(int $licenseId, StatusComputation $computation, string $eventType): bool
    {
        // If no change, do nothing
        if (!$computation->changed) {
            return true;
        }

        $newStatus = $computation->status;
        $graceStartTimestamp = $computation->graceStartTimestamp;

        // Prepare update fields
        $fields = [
            'status' => $newStatus,
        ];

        // Set grace_start_at for active→grace transition
        if ($newStatus === 'grace' && $graceStartTimestamp !== null) {
            $fields['grace_start_at'] = gmdate('Y-m-d H:i:s', $graceStartTimestamp);
        }

        // For grace→expired, preserve the existing grace_start_at (already in $computation)
        // No need to update grace_start_at field in this case

        // Use optimistic locking for active→grace transition
        $whereConditions = [];
        if ($newStatus === 'grace') {
            $whereConditions['status'] = 'active';
        }

        try {
            // Update the license
            $updated = $this->licenseRepo->updateFields($licenseId, $fields, $whereConditions);

            if (!$updated) {
                // Optimistic lock failed (another process already transitioned this license)
                Logger::info("StatusTransitionApplier: Optimistic lock failed for license {$licenseId} (expected)");
                return false;
            }

            // Append event based on transition type
            $this->appendTransitionEvent($licenseId, $newStatus, $eventType);

            return true;
        } catch (\RuntimeException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'new_status' => $newStatus,
                'event_type' => $eventType,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                "Failed to apply status transition for license {$licenseId}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Append appropriate event for the status transition.
     *
     * Events:
     * - active→grace: Uses the provided $eventType ('silent_lapse_grace' or 'sweep_grace_transition')
     * - grace→expired: Uses 'sweep_expiry_transition' (or nothing for silent expiration)
     *
     * @param int $licenseId License ID
     * @param string $newStatus New status after transition
     * @param string $eventType Event type to use for active→grace
     */
    private function appendTransitionEvent(int $licenseId, string $newStatus, string $eventType): void
    {
        // Only append events for active→grace transitions
        // grace→expired is silent (no event)
        if ($newStatus === 'grace') {
            try {
                $this->eventRepo->append($licenseId, $eventType, [
                    'transition' => 'active_to_grace',
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                // Event logging failure should not block the status transition
                $context = ErrorContext::describe($e, [
                    'method' => __METHOD__,
                    'license_id' => $licenseId,
                    'event_type' => $eventType,
                ]);
                Logger::error($context);
            }
        }

        // For sweep job: append expiry event if needed
        if ($newStatus === 'expired' && str_starts_with($eventType, 'sweep_')) {
            try {
                $this->eventRepo->append($licenseId, 'sweep_expiry_transition', [
                    'transition' => 'grace_to_expired',
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                $context = ErrorContext::describe($e, [
                    'method' => __METHOD__,
                    'license_id' => $licenseId,
                ]);
                Logger::error($context);
            }
        }
    }
}
