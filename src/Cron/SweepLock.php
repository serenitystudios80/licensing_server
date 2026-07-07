<?php

declare(strict_types=1);

namespace App\Cron;

use App\Config\Config;
use App\Repository\Db;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;
use PDOException;

/**
 * SweepLock - MariaDB advisory lock for Sweep_Job.
 *
 * Prevents overlapping runs of the Sweep_Job cron script (Requirement 12 AC8).
 * Uses MariaDB's GET_LOCK() function which survives VPS reboots and works even
 * if the process was killed uncleanly (lock is released automatically when the
 * holding connection closes).
 *
 * This is more reliable than a PID file on a VPS that may reboot unexpectedly.
 *
 * Per Requirements 12.8 and design.md Cron and locking section.
 */
final class SweepLock
{
    private const LOCK_NAME = 'serb_sweep_job';
    private const LOCK_TIMEOUT = 0; // No wait - return immediately if already held

    private PDO $pdo;
    private bool $isHeld = false;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Attempt to acquire the sweep job advisory lock.
     *
     * Uses MariaDB GET_LOCK() with 0 timeout (returns immediately if already held).
     *
     * CRITICAL: The lock is tied to the database connection. When the connection
     * closes (script exit, process kill, connection timeout), MariaDB automatically
     * releases the lock. This makes it more resilient than PID files.
     *
     * @return bool True if lock acquired, false if already held by another process
     * @throws \RuntimeException on database error (distinguishable from "already held")
     */
    public function acquire(): bool
    {
        try {
            // GET_LOCK(name, timeout) returns:
            // - 1 if lock acquired successfully
            // - 0 if lock could not be acquired (already held or timeout)
            // - NULL on error
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $stmt->execute([self::LOCK_NAME, self::LOCK_TIMEOUT]);
            $result = $stmt->fetchColumn();

            if ($result === null || $result === false) {
                throw new \RuntimeException(
                    "GET_LOCK() returned unexpected value (NULL or false). " .
                    "This indicates a database error, not a held lock."
                );
            }

            $this->isHeld = ($result === 1);
            
            if ($this->isHeld) {
                Logger::info(
                    "Sweep job lock acquired successfully (lock name: " . self::LOCK_NAME . ")"
                );
            } else {
                Logger::info(
                    "Sweep job lock already held by another process (lock name: " . self::LOCK_NAME . "). " .
                    "This run will exit immediately without processing."
                );
            }

            return $this->isHeld;

        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'lock_name' => self::LOCK_NAME,
                'lock_timeout' => self::LOCK_TIMEOUT,
            ]);
            Logger::error($context);
            
            throw new \RuntimeException(
                "Failed to acquire sweep job lock: database error. Check logs for details. " .
                "Lock name: " . self::LOCK_NAME,
                0,
                $e
            );
        }
    }

    /**
     * Release the sweep job advisory lock.
     *
     * This is optional - the lock is automatically released when the connection closes.
     * Calling this explicitly is good practice for clean shutdown.
     *
     * @return bool True if lock released successfully, false if lock was not held
     * @throws \RuntimeException on database error
     */
    public function release(): bool
    {
        if (!$this->isHeld) {
            return false; // Lock was never acquired by this instance
        }

        try {
            // RELEASE_LOCK(name) returns:
            // - 1 if lock released successfully
            // - 0 if lock was not held by this connection
            // - NULL if lock doesn't exist (never acquired)
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([self::LOCK_NAME]);
            $result = $stmt->fetchColumn();

            if ($result === 1) {
                $this->isHeld = false;
                Logger::info(
                    "Sweep job lock released successfully (lock name: " . self::LOCK_NAME . ")"
                );
                return true;
            }

            // Result is 0 or NULL - lock wasn't held or doesn't exist
            // This is unexpected if isHeld=true, but not a critical failure
            Logger::warning(
                "RELEASE_LOCK() returned {$result} for lock " . self::LOCK_NAME . ". " .
                "Lock may have already been released or never existed."
            );
            
            $this->isHeld = false;
            return false;

        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'lock_name' => self::LOCK_NAME,
            ]);
            Logger::error($context);
            
            throw new \RuntimeException(
                "Failed to release sweep job lock: database error. Check logs for details. " .
                "Lock name: " . self::LOCK_NAME,
                0,
                $e
            );
        }
    }

    /**
     * Check if this instance holds the lock.
     *
     * @return bool True if lock is held by this instance
     */
    public function isHeld(): bool
    {
        return $this->isHeld;
    }

    /**
     * Destructor - releases lock when object is destroyed.
     *
     * This ensures the lock is released even if release() is not called explicitly.
     * However, the lock is also automatically released when the DB connection closes,
     * so this is a redundant safety measure.
     */
    public function __destruct()
    {
        if ($this->isHeld) {
            try {
                $this->release();
            } catch (\Exception $e) {
                // Log but don't throw in destructor (PHP best practice)
                Logger::error(
                    "Failed to release sweep job lock in destructor: {$e->getMessage()}"
                );
            }
        }
    }
}
