<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * AdminUser domain value object.
 *
 * Plain PHP object representing a row from the `admin_users` table.
 *
 * Per Requirements 13.4 and design.md Data Models section.
 */
final readonly class AdminUser
{
    public function __construct(
        public int $id,
        public string $username,
        public string $passwordHash,
        public string $createdAt,
    ) {
    }

    /**
     * Hydrate an AdminUser instance from a database row.
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
