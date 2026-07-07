<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Activation domain value object.
 *
 * Plain PHP object representing a row from the `license_activations` table.
 * This is not an ORM entity — just a typed container for row data with
 * readonly properties, used for hydration from database queries.
 *
 * Per Requirements 1.3 and design.md Data Models section.
 */
final readonly class Activation
{
    public function __construct(
        public int $id,
        public int $licenseId,
        public string $siteUrl,           // max 500 characters
        public string $siteHash,          // 64-character lowercase hex SHA-256
        public string $activatedAt,       // DATETIME string from DB (UTC)
        public ?string $lastValidatedAt,  // DATETIME string from DB (UTC), nullable
        public ?string $deactivatedAt,    // DATETIME string from DB (UTC), nullable
    ) {
    }

    /**
     * Hydrate an Activation instance from a database row (associative array).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            licenseId: (int) $row['license_id'],
            siteUrl: (string) $row['site_url'],
            siteHash: (string) $row['site_hash'],
            activatedAt: (string) $row['activated_at'],
            lastValidatedAt: $row['last_validated_at'] !== null
                ? (string) $row['last_validated_at']
                : null,
            deactivatedAt: $row['deactivated_at'] !== null
                ? (string) $row['deactivated_at']
                : null,
        );
    }
}
