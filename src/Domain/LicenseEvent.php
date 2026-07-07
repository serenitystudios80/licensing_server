<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * LicenseEvent domain value object.
 *
 * Plain PHP object representing a row from the `license_events` table.
 * This is not an ORM entity — just a typed container for row data with
 * readonly properties, used for hydration from database queries.
 *
 * Per Requirements 1.8 and design.md Data Models section.
 * LicenseEvents are immutable audit log entries (append-only).
 */
final readonly class LicenseEvent
{
    public function __construct(
        public int $id,
        public ?int $licenseId,           // nullable only for webhook_unmatched events (Req 7 AC7)
        public string $eventType,         // e.g., 'activation', 'deactivation', 'webhook_charged', etc.
        public string $payload,           // JSON string
        public string $createdAt,         // DATETIME string from DB (UTC)
    ) {
    }

    /**
     * Hydrate a LicenseEvent instance from a database row (associative array).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            licenseId: $row['license_id'] !== null ? (int) $row['license_id'] : null,
            eventType: (string) $row['event_type'],
            payload: (string) $row['payload'],
            createdAt: (string) $row['created_at'],
        );
    }
}
