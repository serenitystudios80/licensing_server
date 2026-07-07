<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * AdminUser domain value object.
 *
 * Plain PHP object representing a row from the `admin_users` table.
 * This is not an ORM entity — just a typed container for row data with
 * readonly properties, used for hydration from database queries.
 *
 * Per Requirement 13.4 and design.md Data Models section.
 * The system supports only a single admin user (no multi-admin roles).
 */
final readonly class AdminUser
{
    public function __construct(
        public int $id,
        public string $username,         // max 64 characters
        public string $passwordHash,     // bcrypt/argon2id hash from password_hash()
        public string $createdAt,        // DATETIME string from DB (UTC)
    ) {
    }

    /**
     * Hydrate an AdminUser instance from a database row (associative array).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) $row['username'],
            passwordHash: (string) $row['password_hash'],
            createdAt: (string) $row['created_at'],
        );
    }
}
