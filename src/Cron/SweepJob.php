<?php

declare(strict_types=1);

namespace App\Cron;

use App\Config\Config;
use App\Domain\License;
use App\Domain\StatusCalculator;
use App\Domain\StatusTransitionApplier;
use App\RateLimit\RateLimitRepository;
use App\RateLimit\RateLimiter;
use App\Repository\LicenseEventRepository;
use App\Repository\LicenseRepository;
use App\Repository\Db;
use App\Support\Clock;
use App\Support\ErrorContext;
use App\Support\Logger;
use App\Support\SystemClock;

/**
 * SweepJob - Hourly cron script for bulk license status transitions.
 *
 * This script is the ONLY mechanism that actively seeks out licenses needing
 * status transitions (active→grace, grace→expired) by scanning the licenses table.
 * It runs hourly, triggered by a server crontab entry wired via ServerAvatar's cron UI.
 *
 * CRITICAL DESIGN POINTS (from design.md StatusCalculator section):
 * - Uses the SAME StatusCalculator::compute() function as the Lazy_Check (/validate)
 * - Shares the SAME persistence rule via StatusTransitionApplier
 * - This structural sharing guarantees both mechanisms converge on the same stored state
 *
 * PER-ITEM RESILIENCE (Requirement 12 AC7):
 * - Each license row is processed inside its own try/catch
 * - Failure on one license (transient DB error, etc.) is logged + recorded as 'sweep_error'
 * - Processing continues to the next license - one failure does NOT abort the run
 *
 * LOCK-BASED OVERLAP PREVENTION (Requirement 12 AC8):
 * - Acquires MariaDB advisory lock via SweepLock at start
 * - If lock already held → exits immediately with zero DB writes (no partial runs)
 *
 * PROCESSING SCOPE (Requirements 12.1, 12.5, 12.6):
 * - Only processes tier='annual' licenses
 * - Excludes status='revoked' licenses (they never transition)
 * - Excludes tier='lifetime' licenses (they never expire)
 * - Pages through matching licenses in batches (bounded memory on large tables)
 *
 * CLEANUP (Requirement 2 AC5, AC6):
 * - Calls RateLimitRepository::cleanup() at end of run
 * - Cleanup failure is logged but does NOT fail the run
 *
 * Per Requirements 12.1-12.8, 2.5, 2.6 and design.md SweepJob section.
 *
 * USAGE (from server crontab via ServerAvatar):
 *   0 * * * * /usr/bin/php /path/to/src/Cron/SweepJob.php
 *
 * EXIT CODES:
 *   0 - Success (all eligible licenses processed, or lock already held)
 *   1 - Critical failure (config missing, lock acquisition error, etc.)
 */
final class SweepJob
{
    private const PAGE_SIZE = 100; // Process licenses in batches of 100

    private Config $config;
    private Clock $clock;
    private SweepLock $lock;
    private LicenseRepository $licenseRepo;
    private LicenseEventRepository $eventRepo;
    private StatusTransitionApplier $applier;
    private RateLimitRepository $rateLimitRepo;
    private RateLimiter $rateLimiter;

    private int $processedCount = 0;
    private int $transitionedCount = 0;
    private int $errorCount = 0;

    public function __construct(
        Config $config,
        Clock $clock,
        SweepLock $lock,
        LicenseRepository $licenseRepo,
        LicenseEventRepository $eventRepo,
        StatusTransitionApplier $applier,
        RateLimitRepository $rateLimitRepo,
        RateLimiter $rateLimiter,
    ) {
        $this->config = $config;
        $this->clock = $clock;
        $this->lock = $lock;
        $this->licenseRepo = $licenseRepo;
        $this->eventRepo = $eventRepo;
        $this->applier = $applier;
        $this->rateLimitRepo = $rateLimitRepo;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Execute the sweep job.
     *
     * @return int Exit code (0 = success, 1 = critical failure)
     */
    public function run(): int
    {
        $startTime = $this->clock->now();
        Logger::info("Sweep job started at " . date('Y-m-d H:i:s', $startTime));

        try {
            // Step 1: Acquire lock (exits immediately if already held)
            if (!$this->lock->acquire()) {
                Logger::info(
                    "Sweep job exiting: lock already held by another process. " .
                    "No licenses processed."
                );
                return 0; // Not an error - normal behavior when another run is active
            }

            // Step 2: Process licenses in pages
            $this->processLicensesInPages($startTime);

            // Step 3: Cleanup rate limit store (wrapped in try/catch per Requirement 2 AC6)
            $this->cleanupRateLimitStore($startTime);

            // Step 4: Release lock (automatic on connection close, but explicit is cleaner)
            $this->lock->release();

            $duration = $this->clock->now() - $startTime;
            Logger::info(
                "Sweep job completed successfully in {$duration}s. " .
                "Processed: {$this->processedCount}, Transitioned: {$this->transitionedCount}, " .
                "Errors: {$this->errorCount}"
            );

            return 0;

        } catch (\Exception $e) {
            // Critical failure (config error, lock acquisition failure, etc.)
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'processed_count' => $this->processedCount,
                'transitioned_count' => $this->transitionedCount,
                'error_count' => $this->errorCount,
            ]);
            Logger::error($context);

            echo "CRITICAL ERROR: Sweep job failed. See logs for details.\n";
            echo "Error: {$e->getMessage()}\n";

            return 1;
        }
    }

    /**
     * Process licenses in pages (bounded memory on large tables).
     *
     * Queries licenses WHERE tier='annual' AND status IN ('active', 'grace')
     * ORDER BY id ASC for stable pagination.
     *
     * Per Requirements 12.1, 12.5, 12.6.
     *
     * @param int $now Current Unix epoch timestamp
     */
    private function processLicensesInPages(int $now): void
    {
        $lastProcessedId = 0;

        while (true) {
            // Fetch next page of eligible licenses
            $licenses = $this->fetchNextPage($lastProcessedId);

            if (empty($licenses)) {
                break; // No more licenses to process
            }

            // Process each license in this page
            foreach ($licenses as $license) {
                $this->processLicense($license, $now);
                $lastProcessedId = $license->id;
            }
        }
    }

    /**
     * Fetch next page of eligible licenses.
     *
     * Eligible = tier='annual' AND status IN ('active', 'grace') AND id > lastProcessedId
     * ORDER BY id ASC LIMIT PAGE_SIZE
     *
     * @param int $lastProcessedId Last processed license ID (for cursor-based pagination)
     * @return License[] Array of licenses (empty if no more)
     */
    private function fetchNextPage(int $lastProcessedId): array
    {
        try {
            // Direct PDO access for this specific sweep query
            // (LicenseRepository doesn't have a general-purpose cursor-based pagination method)
            $pdo = Db::getConnection($this->config);

            $stmt = $pdo->prepare(
                'SELECT * FROM licenses 
                 WHERE tier = ? 
                   AND status IN (?, ?)
                   AND id > ?
                 ORDER BY id ASC
                 LIMIT ?'
            );

            $stmt->execute(['annual', 'active', 'grace', $lastProcessedId, self::PAGE_SIZE]);
            $rows = $stmt->fetchAll();

            return array_map(fn($row) => License::fromRow($row), $rows);

        } catch (\Exception $e) {
            // Query failure is critical - can't continue processing without data
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'last_processed_id' => $lastProcessedId,
            ]);
            Logger::error($context);
            
            throw new \RuntimeException(
                "Failed to fetch next page of licenses for sweep job: {$e->getMessage()}. " .
                "Last processed ID: {$lastProcessedId}",
                0,
                $e
            );
        }
    }

    /**
     * Process a single license (inside per-item try/catch for resilience).
     *
     * Per-item resilience (Requirement 12 AC7):
     * - Wraps each license in try/catch
     * - Logs failure + appends 'sweep_error' event
     * - Continues to next license (does NOT abort run)
     *
     * @param License $license The license to process
     * @param int $now Current Unix epoch timestamp
     */
    private function processLicense(License $license, int $now): void
    {
        $this->processedCount++;

        try {
            // Pre-filter: Skip if shouldExclude() returns true
            // (shouldn't happen since query already filters, but defensive check)
            if (StatusCalculator::shouldExclude($license)) {
                Logger::warning(
                    "Sweep job encountered excluded license in query results: " .
                    "License ID {$license->id}, tier {$license->tier}, status {$license->status}. " .
                    "This should not happen - query should filter these out."
                );
                return;
            }

            // Compute true current status using shared StatusCalculator
            $computation = StatusCalculator::compute($license, $now);

            // Apply transition if status changed
            if ($computation->changed) {
                $transitioned = $this->applier->apply($license, $computation, 'sweep_job');
                
                if ($transitioned) {
                    $this->transitionedCount++;
                    Logger::info(
                        "Sweep job transitioned license {$license->id}: " .
                        "{$license->status} → {$computation->status}"
                    );
                }
            }

        } catch (\Exception $e) {
            // Per-item failure: log + append sweep_error event, continue to next license
            $this->errorCount++;
            
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $license->id,
                'license_key' => $license->licenseKey,
                'current_status' => $license->status,
                'tier' => $license->tier,
            ]);
            Logger::error($context);

            // Attempt to append sweep_error event (wrapped in try/catch to not fail twice)
            try {
                $this->eventRepo->append($license->id, 'sweep_error', [
                    'error' => $e->getMessage(),
                    'context' => 'sweep_job',
                    'timestamp' => $now,
                ]);
            } catch (\Exception $eventError) {
                // Failed to append event - log but don't throw (double-failure case)
                Logger::error(
                    "Failed to append sweep_error event for license {$license->id}: " .
                    "{$eventError->getMessage()}"
                );
            }

            // Continue to next license (do NOT throw - per-item resilience)
        }
    }

    /**
     * Clean up old rate limit records (Requirement 2 AC5, AC6).
     *
     * Wrapped in try/catch so cleanup failure does NOT fail the run.
     *
     * @param int $now Current Unix epoch timestamp
     */
    private function cleanupRateLimitStore(int $now): void
    {
        try {
            $maxWindowSeconds = $this->rateLimiter->getMaxWindowSeconds();
            $success = $this->rateLimitRepo->cleanup($maxWindowSeconds, $now);

            if ($success) {
                Logger::info(
                    "Rate limit cleanup completed successfully " .
                    "(boundary: now - {$maxWindowSeconds}s)"
                );
            } else {
                Logger::warning(
                    "Rate limit cleanup failed (returned false). " .
                    "See earlier logs for details. Sweep job continuing."
                );
            }

        } catch (\Exception $e) {
            // Cleanup failure is logged but does NOT abort the run (Requirement 2 AC6)
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'now' => $now,
            ]);
            Logger::error($context);
            
            Logger::warning(
                "Rate limit cleanup threw exception but sweep job will continue. " .
                "Check logs for details."
            );
        }
    }

    /**
     * Get processing statistics (for testing/monitoring).
     *
     * @return array{processed: int, transitioned: int, errors: int}
     */
    public function getStats(): array
    {
        return [
            'processed' => $this->processedCount,
            'transitioned' => $this->transitionedCount,
            'errors' => $this->errorCount,
        ];
    }
}

// ===== CLI ENTRY POINT =====

// Only run if invoked as a script (not when included/required by tests)
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // Autoload via Composer
    require_once __DIR__ . '/../../vendor/autoload.php';

    try {
        // Load configuration
        $config = Config::load();

        // Create dependencies
        $clock = new SystemClock();
        $lock = new SweepLock($config);
        $licenseRepo = new LicenseRepository($config);
        $eventRepo = new LicenseEventRepository($config);
        $applier = new StatusTransitionApplier($licenseRepo, $eventRepo);
        $rateLimitRepo = new RateLimitRepository($config);
        $rateLimiter = new RateLimiter($config, $rateLimitRepo);

        // Create and run sweep job
        $job = new SweepJob(
            $config,
            $clock,
            $lock,
            $licenseRepo,
            $eventRepo,
            $applier,
            $rateLimitRepo,
            $rateLimiter
        );

        $exitCode = $job->run();
        exit($exitCode);

    } catch (\Exception $e) {
        // Top-level exception handler for CLI execution
        echo "FATAL ERROR: {$e->getMessage()}\n";
        echo "Stack trace:\n{$e->getTraceAsString()}\n";
        exit(1);
    }
}
