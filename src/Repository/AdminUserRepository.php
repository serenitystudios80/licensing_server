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
 * AdminUserRepository - Repository for admin user authentication.
 *
 * Per Requirements 13.4 and design.md Admin authentication section.
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
     * @return AdminUser|null Returns null if not found.
     * @throws \RuntimeException on database error.
     */
    public function findByUsername(string $username): ?AdminUser
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM admin_users WHERE username = :username LIMIT 1'
            );
            $stmt->execute(['username' => $username]);

            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            return AdminUser::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'username' => $username,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query admin user: database error',
                0,
                $e
            );
        }
    }

    /**
     * Create a new admin user.
     *
     * @param array{
     *     username: string,
     *     password_hash: string,
     * } $data
     *
     * @return AdminUser The newly created admin user.
     * @throws \RuntimeException on database error.
     */
    public function create(array $data): AdminUser
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO admin_users (username, password_hash, created_at)
                 VALUES (:username, :password_hash, NOW())'
            );

            $stmt->execute([
                'username' => $data['username'],
                'password_hash' => $data['password_hash'],
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $created = $this->findByUsername($data['username']);
            if ($created === null) {
                throw new \RuntimeException(
                    'Admin user was created but could not be retrieved'
                );
            }

            return $created;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'username' => $data['username'],
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to create admin user: database error',
                0,
                $e
            );
        }
    }

    /**
     * Record a login attempt.
     *
     * @param string $username Username attempted
     * @param bool $successful Whether login succeeded
     * @param string $ipAddress IP address of attempt
     * @param int $timestamp Unix timestamp of attempt
     */
    public function recordLoginAttempt(
        string $username,
        bool $successful,
        string $ipAddress,
        int $timestamp
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO admin_login_attempts (username, successful, ip_address, attempted_at)
                 VALUES (:username, :successful, :ip_address, FROM_UNIXTIME(:timestamp))'
            );

            $stmt->execute([
                'username' => $username,
                'successful' => $successful ? 1 : 0,
                'ip_address' => $ipAddress,
                'timestamp' => $timestamp,
            ]);
        } catch (PDOException $e) {
            // Log but don't throw - login attempt tracking should not block login
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'username' => $username,
            ]);
            Logger::error($context);
        }
    }

    /**
     * Count failed login attempts for a username within a time window.
     *
     * @param string $username Username to check
     * @param int $sinceTimestamp Count attempts since this timestamp
     * @return int Number of failed attempts
     */
    public function countFailedAttemptsSince(string $username, int $sinceTimestamp): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM admin_login_attempts
                 WHERE username = :username
                   AND successful = 0
                   AND attempted_at >= FROM_UNIXTIME(:since)'
            );

            $stmt->execute([
                'username' => $username,
                'since' => $sinceTimestamp,
            ]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'username' => $username,
            ]);
            Logger::error($context);

            // Fail-open: if we can't check, don't block login
            return 0;
        }
    }
}
