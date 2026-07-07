<?php

declare(strict_types=1);

namespace App\Audit;

use App\Repository\LicenseEventRepository;
use App\Support\Logger;
use App\Support\ErrorContext;

/**
 * AuditLogger - Wrapper for audit event logging with failure resilience.
 *
 * Wraps LicenseEventRepository::append() with try/catch so that audit logging
 * failures NEVER throw to the caller. Primary state mutations (activation,
 * deactivation, status transitions) must complete successfully even if the
 * audit log write fails.
 *
 * Called AFTER the primary state mutation has committed.
 *
 * Per Requirements 22.1, 22.2, 22.4 and design.md AuditLogger section.
 */
final class AuditLogger
{
    private LicenseEventRepository $eventRepo;

    public function __construct(LicenseEventRepository $eventRepo)
    {
        $this->eventRepo = $eventRepo;
    }

    /**
     * Record an audit event.
     *
     * CRITICAL: This method NEVER throws. Failures are logged but do NOT
     * propagate to the caller (Requirement 22 AC4).
     *
     * @param int|null $licenseId License ID (nullable only for webhook_unmatched)
     * @param string $eventType Event type (e.g., 'activation', 'deactivation', 'silent_lapse_grace')
     * @param array<string, mixed> $payload Event payload (will be JSON-encoded)
     * @return bool True on success, false on failure (logged)
     */
    public function record(?int $licenseId, string $eventType, array $payload): bool
    {
        try {
            $this->eventRepo->append($licenseId, $eventType, $payload);
            return true;

        } catch (\Throwable $e) {
            // Log the failure but DO NOT throw (fail-silently per Requirement 22 AC4)
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'event_type' => $eventType,
            ]);

            // Attempt to log the failure
            // If this logging attempt itself fails, swallow that too (belt-and-suspenders)
            try {
                Logger::error(
                    "Audit logging failed for license {$licenseId}, event type '{$eventType}': " .
                    $e->getMessage() . ". Context: {$context}"
                );
            } catch (\Throwable $logError) {
                // Even logging the failure failed - nothing more we can do
                // Execution continues normally (primary mutation succeeded)
            }

            return false;
        }
    }
}
