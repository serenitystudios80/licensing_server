<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Config;
use App\Domain\LicenseEvent;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;
use PDOException;

/**
 * Repository for LicenseEvent entities.
 *
 * CRITICAL DESIGN CONSTRAINT: This repository exposes ONLY the append() method.
 * No update or delete method exists on this class, structurally enforcing the
 * append-only, immutable audit log requirement (Requirements 1.7, 22.3,
 * Correctness Property 3).
 *
 * The database-level enforcement is application-side: no code path in the
 * system issues UPDATE or DELETE against license_events. MariaDB triggers
 * would require SUPER privileges not guaranteed on shared VPS hosting, so
 * application-level structural enforcement is deliberate here.
 *
 * All queries use PDO prepared statements exclusively.
 */
final class LicenseEventRepository
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Append a new event to the immutable audit log.
     *
     * This is the ONLY mutation method exposed by this repository.
     * No update() or delete() method exists.
     *
     * @param int|null $licenseId License ID (nullable only for webhook_unmatched events per Req 7 AC7)
     * @param string $eventType Event type (e.g., 'activation', 'deactivation', 'webhook_charged', etc.)
     * @param array<string, mixed> $payload Event payload (will be JSON-encoded)
     * @return LicenseEvent The created event entity
     * @throws \RuntimeException on append failure
     */
    public function append(?int $licenseId, string $eventType, array $payload): LicenseEvent
    {
        try {
            // JSON-encode the payload
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

            $stmt = $this->pdo->prepare(
                'INSERT INTO license_events (license_id, event_type, payload) 
                 VALUES (?, ?, ?)'
            );

            $stmt->execute([
                $licenseId,
                $eventType,
                $payloadJson,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            // Fetch the created event to return full entity
            $stmt = $this->pdo->prepare('SELECT * FROM license_events WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new \RuntimeException(
                    "License event created but could not be retrieved. Event ID: {$id}"
                );
            }

            return LicenseEvent::fromRow($row);
        } catch (\JsonException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'event_type' => $eventType,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to append license event: JSON encoding error for event type '{$eventType}'. " .
                "Check logs for details.",
                0,
                $e
            );
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'event_type' => $eventType,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to append license event for license {$licenseId} with event type '{$eventType}': " .
                "database insert error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Find all events for a license (ordered by created_at ASC).
     *
     * Read-only query for admin panel license detail view (task 23).
     * Does NOT expose any mutation capability.
     *
     * @param int $licenseId License ID
     * @return LicenseEvent[] Array of events
     */
    public function findAllForLicense(int $licenseId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_events 
                 WHERE license_id = ?
                 ORDER BY created_at ASC'
            );
            $stmt->execute([$licenseId]);

            return array_map(
                fn($row) => LicenseEvent::fromRow($row),
                $stmt->fetchAll()
            );
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find events for license {$licenseId}: database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Check if a webhook event ID has already been recorded (idempotency check).
     *
     * Used by RazorpayWebhookHandler to detect idempotent replay
     * (Requirement 7 AC8, Correctness Property 23).
     *
     * Queries the generated column webhook_event_id (indexed) for fast lookup
     * instead of scanning JSON payloads.
     *
     * @param string $webhookEventId Webhook event ID from Razorpay
     * @return bool True if event already recorded, false otherwise
     */
    public function hasWebhookEventId(string $webhookEventId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM license_events 
                 WHERE webhook_event_id = ? 
                   AND event_type LIKE ?
                 LIMIT 1'
            );
            $stmt->execute([$webhookEventId, 'webhook_%']);

            return ((int) $stmt->fetchColumn()) > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'webhook_event_id' => $webhookEventId,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to check webhook event ID '{$webhookEventId}': database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Count total events for a license (for admin stats/debugging).
     *
     * Read-only query. Does NOT expose any mutation capability.
     *
     * @param int $licenseId License ID
     * @return int Total event count
     */
    public function countForLicense(int $licenseId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM license_events WHERE license_id = ?'
            );
            $stmt->execute([$licenseId]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to count events for license {$licenseId}: database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Find events by event type (for admin filtering/debugging).
     *
     * Read-only query. Does NOT expose any mutation capability.
     *
     * @param string $eventType Event type to filter by
     * @return LicenseEvent[] Array of matching events
     */
    public function findByEventType(string $eventType): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_events 
                 WHERE event_type = ?
                 ORDER BY created_at DESC
                 LIMIT 100'
            );
            $stmt->execute([$eventType]);

            return array_map(
                fn($row) => LicenseEvent::fromRow($row),
                $stmt->fetchAll()
            );
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'event_type' => $eventType,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find events by type '{$eventType}': database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    // DELIBERATELY NO update() OR delete() METHODS.
    // This structural omission enforces the append-only requirement.
    // Any attempt to add such methods should trigger code review rejection.
}
