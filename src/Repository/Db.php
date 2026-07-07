<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Config;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;
use PDOException;

/**
 * Thin PDO factory/wrapper for database access.
 *
 * This is NOT an ORM or query builder — just a minimal connection factory
 * that enforces prepared statements and provides connection-level error
 * handling with specific diagnostic messages per the error-handling policy.
 *
 * All queries in repository classes use PDO prepared statements exclusively
 * to prevent SQL injection.
 */
final class Db
{
    private static ?PDO $connection = null;

    /**
     * Get the shared PDO connection.
     *
     * Lazy-initializes the connection on first call using credentials from Config.
     * Subsequent calls return the same connection (connection pooling at app level).
     *
     * @throws \RuntimeException if connection fails, with specific diagnostic context
     */
    public static function getConnection(Config $config): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $host = $config->get('DB_HOST');
        $dbName = $config->get('DB_NAME');
        $user = $config->get('DB_USER');
        $password = $config->get('DB_PASS');

        $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, // Use real prepared statements
            ]);

            self::$connection = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            // Build specific diagnostic message: what failed, why, where to check
            $message = sprintf(
                'DB connection failed: could not reach MariaDB at host %s for database %s. ' .
                'Check DB_HOST, DB_NAME, DB_USER, DB_PASS in .env. ' .
                'Underlying error: %s',
                $host,
                $dbName,
                $e->getMessage()
            );

            // Log with full context (credentials redacted by Logger's secret-redaction rule)
            $context = ErrorContext::describe($e, [
                'host' => $host,
                'database' => $dbName,
                'user' => $user,
                // password intentionally omitted — Logger would redact it anyway
            ]);
            Logger::error($context);

            throw new \RuntimeException($message, 0, $e);
        }
    }

    /**
     * Close the shared connection (for testing/cleanup).
     *
     * Not typically called in production (PHP closes connections at script end),
     * but useful for test isolation.
     */
    public static function closeConnection(): void
    {
        self::$connection = null;
    }
}
