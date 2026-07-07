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
 * Repository for LicenseEvent domain objects.
 *
 * This repository is append-only by design: it exposes ONLY an `append()` method.
 * No update or delete methods exist on the class, structurally enforcing the
 * append-only, immutable audit log requirement.
 *
 * Per Requirements 1.7, 1.8, 22.3 and design.md Repository layer section.
 */
final class LicenseEventRepository
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Append a new event to the license_events log.
     *
     * This is the ONLY mutating method on this repository — append-only semantics.
     * No update or delete is ever exposed.
     *
     * @param int $licenseId The License this event belongs to.
     * @param string $eventType The event type (e.g., 'activation', 'webhook_charged').
     * @param array<string, mixed> $payload The event payload (stored as JSON).
     *
     * @return LicenseEvent The newly created LicenseEvent with its database-assigned id.
     * @throws \RuntimeException on database error.
     */
    public function append(int $licenseId, string $eventType, array $payload): LicenseEvent
    {
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO license_events (license_id, event_type, payload)
                 VALUES (:license_id, :event_type, :payload)'
            );

            $stmt->execute([
                'license_id' => $licenseId,
                'event_type' => $eventType,
                'payload' => $payloadJson,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $created = $this->findById($id);
            if ($created === null) {
                throw new \RuntimeException(
                    'LicenseEvent was created but could not be retrieved'
                );
            }

            return $created;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'event_type' => $eventType,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to append LicenseEvent: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find a LicenseEvent by its primary key id.
     *
     * Read-only helper for retrieving events (used internally after append,
     * and by admin views).
     *
     * @return LicenseEvent|null Returns null if not found.
     * @throws \RuntimeException on database error.
     */
    public function findById(int $id): ?LicenseEvent
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_events WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $id]);

            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            return LicenseEvent::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'id' => $id,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query LicenseEvent by id: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find all events for a License (sorted by created_at ascending).
     *
     * Read-only helper for admin detail views.
     *
     * @return list<LicenseEvent>
     * @throws \RuntimeException on database error.
     */
    public function findAllForLicense(int $licenseId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_events
                 WHERE license_id = :license_id
                 ORDER BY created_at ASC'
            );
            $stmt->execute(['license_id' => $licenseId]);

            $rows = $stmt->fetchAll();
            return array_map(fn($row) => LicenseEvent::fromRow($row), $rows);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query LicenseEvents for License: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find a LicenseEvent by webhook_event_id (for idempotent webhook replay).
     *
     * The webhook_event_id is a generated column in the database extracted from
     * the JSON payload. This query is used by the Razorpay webhook handler to
     * detect duplicate webhook deliveries.
     *
     * @return LicenseEvent|null Returns null if not found.
     * @throws \RuntimeException on database error.
     */
    public function findByWebhookEventId(string $webhookEventId): ?LicenseEvent
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_events
                 WHERE webhook_event_id = :webhook_event_id
                 LIMIT 1'
            );
            $stmt->execute(['webhook_event_id' => $webhookEventId]);

            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            return LicenseEvent::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'webhook_event_id' => $webhookEventId,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query LicenseEvent by webhook_event_id: database error',
                0,
                $e
            );
        }
    }
}
