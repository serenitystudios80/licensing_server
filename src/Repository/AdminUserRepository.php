<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Config;
use App\Domain\AdminUser;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;
use PDOException;

/**
 * Repository for AdminUser entities.
 *
 * Provides operations for finding and creating admin users.
 * The system supports only a single admin user (Requirement 13),
 * but the repository is designed to handle multiple rows if needed
 * in future extensions.
 *
 * Password hashing uses PHP's password_hash() / password_verify()
 * (bcrypt or argon2id depending on PHP version).
 *
 * All queries use PDO prepared statements exclusively.
 */
final class AdminUserRepository
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Find an admin user by username.
     *
     * Used by login authentication (Requirement 13).
     *
     * @param string $username Username (case-sensitive)
     * @return AdminUser|null Returns null if not found
     */
    public function findByUsername(string $username): ?AdminUser
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM admin_users WHERE username = ? LIMIT 1'
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            return $row ? AdminUser::fromRow($row) : null;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'username' => $username,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find admin user '{$username}': database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Create a new admin user.
     *
     * Password must already be hashed using password_hash() before calling this method.
     * This repository does NOT hash passwords — that's the caller's responsibility
     * (typically done in the registration/setup controller).
     *
     * @param string $username Username (max 64 chars)
     * @param string $passwordHash Pre-hashed password from password_hash()
     * @return AdminUser The created AdminUser entity
     * @throws \RuntimeException on creation failure
     */
    public function create(string $username, string $passwordHash): AdminUser
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO admin_users (username, password_hash) VALUES (?, ?)'
            );

            $stmt->execute([$username, $passwordHash]);

            $id = (int) $this->pdo->lastInsertId();

            // Fetch the created user to return full entity
            $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new \RuntimeException(
                    "Admin user created but could not be retrieved. User ID: {$id}"
                );
            }

            return AdminUser::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'username' => $username,
                // password_hash intentionally omitted — Logger would redact it anyway
            ]);
            Logger::error($context);

            // Check for duplicate username error
            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'uq_username') !== false) {
                throw new \RuntimeException(
                    "Failed to create admin user: username '{$username}' already exists. " .
                    "Usernames must be unique.",
                    0,
                    $e
                );
            }

            throw new \RuntimeException(
                "Failed to create admin user '{$username}': database insert error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Check if any admin users exist in the system.
     *
     * Used during initial setup to determine if the admin account needs
     * to be created (not implemented in this spec, but useful for future setup flow).
     *
     * @return bool True if at least one admin user exists
     */
    public function exists(): bool
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM admin_users');
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to check admin user existence: database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Find admin user by ID.
     *
     * Used for session management (Requirement 13).
     *
     * @param int $id Admin user ID
     * @return AdminUser|null Returns null if not found
     */
    public function findById(int $id): ?AdminUser
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM admin_users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            return $row ? AdminUser::fromRow($row) : null;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'admin_user_id' => $id,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find admin user by ID {$id}: database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }
}
