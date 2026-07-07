<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Config\Config;
use App\Repository\Db;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;
use PDOException;

/**
 * RateLimitRepository - Storage for rate limit tracking.
 *
 * Operations:
 * - record(): Insert rate limit entry (catches exceptions, never throws)
 * - countSince(): Count entries since timestamp (throws on read failure)
 * - cleanup(): Delete old entries beyond window (catches exceptions)
 *
 * Per Requirements 2.1-2.4 and design.md Rate limiting section.
 */
final class RateLimitRepository
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Record a rate limit entry.
     *
     * Catches its own PDO exceptions, logs them, and NEVER throws to the caller.
     * Write failures are fail-open (rate limiting continues with available data).
     *
     * @param string $scope Scope identifier ('ip' or 'license_key')
     * @param string $identifier IP address or license key
     * @param int $timestamp Unix timestamp when request occurred
     * @return bool True if recorded successfully, false if failed
     */
    public function record(string $scope, string $identifier, int $timestamp): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rate_limit_store (scope, identifier, request_timestamp)
                 VALUES (:scope, :identifier, FROM_UNIXTIME(:timestamp))'
            );

            $stmt->execute([
                'scope' => $scope,
                'identifier' => $identifier,
                'timestamp' => $timestamp,
            ]);

            return true;
        } catch (PDOException $e) {
            // Log but don't throw - rate limiting fails open on write errors
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'scope' => $scope,
                'identifier' => $identifier,
            ]);
            Logger::error($context);

            return false;
        }
    }

    /**
     * Count rate limit entries since a timestamp.
     *
     * THROWS RateLimitStoreException on read failure (unlike record(), read
     * failures need to be signaled so the rate limiter can fail-open that scope).
     *
     * @param string $scope Scope identifier
     * @param string $identifier IP address or license key
     * @param int $since Unix timestamp (count entries >= this timestamp)
     * @return int Count of entries in window
     * @throws RateLimitStoreException on database read error
     */
    public function countSince(string $scope, string $identifier, int $since): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM rate_limit_store
                 WHERE scope = :scope
                   AND identifier = :identifier
                   AND request_timestamp >= FROM_UNIXTIME(:since)'
            );

            $stmt->execute([
                'scope' => $scope,
                'identifier' => $identifier,
                'since' => $since,
            ]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'scope' => $scope,
                'identifier' => $identifier,
            ]);
            Logger::error($context);

            throw new RateLimitStoreException(
                'Failed to read rate limit data: database error',
                0,
                $e
            );
        }
    }

    /**
     * Clean up old rate limit entries.
     *
     * Deletes entries older than maxWindowSeconds. Called by Sweep_Job.
     * Catches exceptions and never throws (cleanup failure is logged but not fatal).
     *
     * @param int $maxWindowSeconds Maximum window size (delete entries older than now - maxWindow)
     * @param int $now Current Unix timestamp
     * @return int Number of rows deleted, or -1 on error
     */
    public function cleanup(int $maxWindowSeconds, int $now): int
    {
        $cutoff = $now - $maxWindowSeconds;

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM rate_limit_store
                 WHERE request_timestamp < FROM_UNIXTIME(:cutoff)'
            );

            $stmt->execute(['cutoff' => $cutoff]);

            return $stmt->rowCount();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'cutoff_timestamp' => $cutoff,
            ]);
            Logger::error($context);

            return -1; // Indicate error to caller
        }
    }
}
