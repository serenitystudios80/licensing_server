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
 * RateLimitRepository - MariaDB-backed rate limit tracking.
 *
 * Implements a sliding-window rate limit store using the rate_limit_store table.
 * No Redis or APCu required — pure MariaDB solution (Requirement 2 intro).
 *
 * KEY BEHAVIORS:
 * - record() catches its own PDO exceptions, logs, NEVER throws (Requirement 2 AC3)
 *   Write failures do NOT abort the request (fail-open for writes)
 * - countSince() THROWS RateLimitStoreException on read failure (Requirement 2 AC3, 9 AC8)
 *   Read failures must be distinguishable from "0 requests" so RateLimiter can fail open correctly
 * - cleanup() deletes old rows (called by Sweep_Job hourly per Requirement 2 AC5)
 *
 * Per Requirements 2.1, 2.2, 2.3, 2.4 and design.md RateLimiter section.
 */
final class RateLimitRepository
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Record a rate limit attempt (write to store).
     *
     * CRITICAL: This method catches its own PDO exceptions internally and NEVER throws.
     * Write failures are logged but do NOT abort the request (Requirement 2 AC3 fail-open).
     *
     * @param string $scope Limiter scope: 'ip' or 'license_key'
     * @param string $scopeValue Scope value (IP address or license key)
     * @param string $endpoint Endpoint name: 'activate', 'validate', 'deactivate'
     * @param int $timestamp Unix epoch timestamp (seconds) when request was received
     * @return void Always returns normally (never throws)
     */
    public function record(string $scope, string $scopeValue, string $endpoint, int $timestamp): void
    {
        try {
            // Convert Unix timestamp to MySQL DATETIME
            $createdAt = date('Y-m-d H:i:s', $timestamp);

            $stmt = $this->pdo->prepare(
                'INSERT INTO rate_limit_store (scope, scope_value, endpoint, created_at) 
                 VALUES (?, ?, ?, ?)'
            );

            $stmt->execute([$scope, $scopeValue, $endpoint, $createdAt]);

        } catch (PDOException $e) {
            // Log the failure but DO NOT throw (fail-open for writes per Requirement 2 AC3)
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'scope' => $scope,
                'scope_value' => $scopeValue,
                'endpoint' => $endpoint,
                'timestamp' => $timestamp,
            ]);
            Logger::error($context);
            
            // Explicitly return normally (no throw) even though catch block already does this
            // This comment serves as documentation of the intentional fail-open behavior
            return;
        }
    }

    /**
     * Count rate limit attempts since a given timestamp (read from store).
     *
     * CRITICAL: This method THROWS RateLimitStoreException on read failure.
     * The caller (RateLimiter) needs to distinguish "0 requests" from "couldn't tell"
     * to implement fail-open correctly (Requirement 9 AC8).
     *
     * @param string $scope Limiter scope: 'ip' or 'license_key'
     * @param string $scopeValue Scope value (IP address or license key)
     * @param int $since Unix epoch timestamp (seconds) - window start
     * @return int Count of attempts since timestamp
     * @throws RateLimitStoreException on database read failure
     */
    public function countSince(string $scope, string $scopeValue, int $since): int
    {
        try {
            // Convert Unix timestamp to MySQL DATETIME
            $sinceDate = date('Y-m-d H:i:s', $since);

            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM rate_limit_store 
                 WHERE scope = ? 
                   AND scope_value = ? 
                   AND created_at >= ?'
            );

            $stmt->execute([$scope, $scopeValue, $sinceDate]);
            
            return (int) $stmt->fetchColumn();

        } catch (PDOException $e) {
            // Log the failure AND throw (read failures must be detectable per Requirement 9 AC8)
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'scope' => $scope,
                'scope_value' => $scopeValue,
                'since' => $since,
            ]);
            Logger::error($context);
            
            throw new RateLimitStoreException(
                "Failed to count rate limit attempts for scope '{$scope}' value '{$scopeValue}': " .
                "database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Clean up old rate limit records (delete rows older than boundary).
     *
     * Called by Sweep_Job hourly (Requirement 2 AC5).
     * Deletes rows with created_at < (now - maxWindowSeconds).
     *
     * Failures are caught, logged, and returned as false (not thrown) so the Sweep_Job
     * can continue processing even if cleanup fails (Requirement 2 AC6).
     *
     * @param int $maxWindowSeconds Maximum sliding window duration (larger of IP/key windows)
     * @param int $now Current Unix epoch timestamp (seconds)
     * @return bool True on success, false on failure (logged)
     */
    public function cleanup(int $maxWindowSeconds, int $now): bool
    {
        try {
            // Calculate boundary timestamp: now - maxWindowSeconds
            $boundary = $now - $maxWindowSeconds;
            $boundaryDate = date('Y-m-d H:i:s', $boundary);

            $stmt = $this->pdo->prepare(
                'DELETE FROM rate_limit_store WHERE created_at < ?'
            );

            $stmt->execute([$boundaryDate]);

            $deletedCount = $stmt->rowCount();
            
            // Log cleanup success (useful for monitoring)
            Logger::info(
                "Rate limit cleanup completed: deleted {$deletedCount} rows older than {$boundaryDate} " .
                "(boundary: now - {$maxWindowSeconds}s)"
            );

            return true;

        } catch (PDOException $e) {
            // Log the failure but return false (not thrown) per Requirement 2 AC6
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'max_window_seconds' => $maxWindowSeconds,
                'now' => $now,
                'boundary' => $now - $maxWindowSeconds,
            ]);
            Logger::error($context);
            
            return false;
        }
    }

    /**
     * Get total count of rate limit records (for admin monitoring/debugging).
     *
     * @return int Total record count
     */
    public function getTotalCount(): int
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM rate_limit_store');
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);
            return 0; // Return 0 on failure (monitoring query, not critical)
        }
    }
}
