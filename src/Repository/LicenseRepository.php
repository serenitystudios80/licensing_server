<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Config;
use App\Domain\License;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;
use PDOException;

/**
 * Repository for License entities.
 *
 * Provides core CRUD operations (findByKey, findById, create, updateFields)
 * and admin query helpers (search, filter, countBy*, expiringWithin).
 *
 * Enforces the tier=lifetime => expires_at=NULL invariant at creation/update
 * time (Requirements 1.2, 1.10).
 *
 * All queries use PDO prepared statements exclusively (no raw SQL concatenation).
 * Admin query helpers build parameterized WHERE fragments from an allow-list of
 * filter keys to prevent SQL injection.
 */
final class LicenseRepository
{
    private PDO $pdo;

    /**
     * Allow-listed filter keys for admin queries.
     * Only these keys are permitted in filter() method to prevent SQL injection.
     */
    private const ALLOWED_FILTER_KEYS = ['status', 'tier', 'product'];

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Find a License by its license_key.
     *
     * @return License|null Returns null if not found
     */
    public function findByKey(string $key): ?License
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM licenses WHERE license_key = ? LIMIT 1'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            return $row ? License::fromRow($row) : null;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $key,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find license by key: database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Find a License by its numeric ID.
     *
     * @return License|null Returns null if not found
     */
    public function findById(int $id): ?License
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM licenses WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            return $row ? License::fromRow($row) : null;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $id,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find license by ID {$id}: database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Create a new License.
     *
     * Enforces the tier=lifetime => expires_at=NULL invariant (Requirements 1.2, 1.10).
     * If tier is 'lifetime' and expires_at is provided, it will be forced to NULL.
     *
     * @param array<string, mixed> $data Associative array with License fields
     * @return License The created License entity
     * @throws \RuntimeException on creation failure
     */
    public function create(array $data): License
    {
        // Enforce lifetime => expires_at=NULL invariant
        if (($data['tier'] ?? null) === 'lifetime') {
            $data['expires_at'] = null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO licenses (
                    license_key, email, customer_name, product, tier, status,
                    purchased_at, expires_at, grace_start_at, razorpay_subscription_id,
                    activation_limit, price_amount, currency, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $data['license_key'],
                $data['email'],
                $data['customer_name'],
                $data['product'],
                $data['tier'],
                $data['status'] ?? 'active',
                $data['purchased_at'],
                $data['expires_at'] ?? null,
                $data['grace_start_at'] ?? null,
                $data['razorpay_subscription_id'] ?? null,
                $data['activation_limit'],
                $data['price_amount'] ?? null,
                $data['currency'] ?? null,
                $data['notes'] ?? '',
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $license = $this->findById($id);

            if ($license === null) {
                throw new \RuntimeException(
                    "License created but could not be retrieved. License ID: {$id}"
                );
            }

            return $license;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $data['license_key'] ?? '(not provided)',
                'email' => $data['email'] ?? '(not provided)',
                'tier' => $data['tier'] ?? '(not provided)',
            ]);
            Logger::error($context);

            // Check for duplicate key error
            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'uq_license_key') !== false) {
                throw new \RuntimeException(
                    "Failed to create license: license_key '{$data['license_key']}' already exists. " .
                    "License keys must be unique.",
                    0,
                    $e
                );
            }

            throw new \RuntimeException(
                "Failed to create license: database insert error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Update specific fields of a License.
     *
     * Performs a targeted field-list update (never a full-row overwrite) so
     * unrelated fields can't be clobbered by a stale read.
     *
     * Enforces the tier=lifetime => expires_at=NULL invariant when updating tier
     * or expires_at (Requirements 1.2, 1.10).
     *
     * @param int $id License ID
     * @param array<string, mixed> $fields Associative array of fields to update
     * @return bool True on success, false if no rows affected
     * @throws \RuntimeException on update failure
     */
    public function updateFields(int $id, array $fields): bool
    {
        if (empty($fields)) {
            return false; // Nothing to update
        }

        // Enforce lifetime => expires_at=NULL invariant
        // If setting tier to 'lifetime', force expires_at to NULL
        if (isset($fields['tier']) && $fields['tier'] === 'lifetime') {
            $fields['expires_at'] = null;
        }

        // If updating expires_at on a lifetime license, prevent it
        // (fetch current tier first to check)
        if (isset($fields['expires_at']) && $fields['expires_at'] !== null) {
            $current = $this->findById($id);
            if ($current && $current->tier === 'lifetime') {
                throw new \InvalidArgumentException(
                    "Cannot set expires_at on lifetime license. License ID: {$id}, tier: lifetime. " .
                    "Lifetime licenses must have expires_at=NULL per Requirements 1.2, 1.10."
                );
            }
        }

        try {
            // Build SET clause from fields
            $setClauses = [];
            $params = [];
            foreach ($fields as $key => $value) {
                $setClauses[] = "{$key} = ?";
                $params[] = $value;
            }
            $params[] = $id; // WHERE clause parameter

            $sql = 'UPDATE licenses SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $id,
                'fields' => array_keys($fields),
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to update license ID {$id}: database update error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Search licenses by email or license_key (case-insensitive substring match).
     *
     * Used by admin panel list/search feature.
     *
     * @param string $query Search query
     * @return License[] Array of matching licenses
     */
    public function search(string $query): array
    {
        try {
            $pattern = '%' . $query . '%';
            $stmt = $this->pdo->prepare(
                'SELECT * FROM licenses 
                 WHERE LOWER(email) LIKE LOWER(?) 
                    OR LOWER(license_key) LIKE LOWER(?)
                 ORDER BY created_at DESC'
            );
            $stmt->execute([$pattern, $pattern]);

            return array_map(
                fn($row) => License::fromRow($row),
                $stmt->fetchAll()
            );
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'query' => $query,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to search licenses: database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Filter licenses by status, tier, product (AND semantics).
     *
     * Only allow-listed filter keys are permitted (status, tier, product).
     * Used by admin panel list/filter feature.
     *
     * @param array<string, string> $filters Associative array of filter criteria
     * @return License[] Array of matching licenses
     * @throws \InvalidArgumentException if non-allowed filter key is provided
     */
    public function filter(array $filters): array
    {
        // Validate all keys are in allow-list
        foreach (array_keys($filters) as $key) {
            if (!in_array($key, self::ALLOWED_FILTER_KEYS, true)) {
                throw new \InvalidArgumentException(
                    "Invalid filter key '{$key}'. Allowed keys: " . implode(', ', self::ALLOWED_FILTER_KEYS)
                );
            }
        }

        if (empty($filters)) {
            // No filters = return all (or handle as needed by admin controller)
            return [];
        }

        try {
            // Build WHERE clause from filters (AND semantics)
            $whereClauses = [];
            $params = [];
            foreach ($filters as $key => $value) {
                $whereClauses[] = "{$key} = ?";
                $params[] = $value;
            }

            $sql = 'SELECT * FROM licenses WHERE ' . implode(' AND ', $whereClauses) .
                   ' ORDER BY created_at DESC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return array_map(
                fn($row) => License::fromRow($row),
                $stmt->fetchAll()
            );
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'filters' => $filters,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to filter licenses: database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Count licenses by status.
     *
     * @param string $status Status value (active, grace, expired, revoked)
     * @return int Count of licenses with that status
     */
    public function countByStatus(string $status): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM licenses WHERE status = ?');
            $stmt->execute([$status]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'status' => $status,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to count licenses by status '{$status}': database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Count licenses by tier.
     *
     * @param string $tier Tier value (annual, lifetime)
     * @return int Count of licenses with that tier
     */
    public function countByTier(string $tier): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM licenses WHERE tier = ?');
            $stmt->execute([$tier]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'tier' => $tier,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to count licenses by tier '{$tier}': database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Get licenses expiring within N days.
     *
     * Only considers non-expired, non-revoked annual licenses with non-null expires_at.
     * Clamps days to 1-365 range.
     *
     * @param int $days Number of days (will be clamped to 1-365)
     * @return License[] Array of expiring licenses
     */
    public function expiringWithin(int $days): array
    {
        // Clamp days to reasonable range
        $days = max(1, min(365, $days));

        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM licenses 
                 WHERE tier = ? 
                   AND status NOT IN (?, ?)
                   AND expires_at IS NOT NULL
                   AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)
                 ORDER BY expires_at ASC'
            );
            $stmt->execute(['annual', 'expired', 'revoked', $days]);

            return array_map(
                fn($row) => License::fromRow($row),
                $stmt->fetchAll()
            );
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'days' => $days,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to get expiring licenses: database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Calculate Monthly Recurring Revenue (MRR).
     *
     * Sums price_amount for active, annual, INR, non-null-price licenses,
     * divided by 12 (annual payment amortized monthly).
     *
     * Used by admin dashboard (task 20).
     *
     * @return float MRR in INR
     */
    public function calculateMRR(): float
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT SUM(price_amount) as total FROM licenses 
                 WHERE status = ? 
                   AND tier = ? 
                   AND currency = ?
                   AND price_amount IS NOT NULL'
            );
            $stmt->execute(['active', 'annual', 'INR']);
            $total = $stmt->fetchColumn();

            return $total ? (float) $total / 12.0 : 0.0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to calculate MRR: database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Get all licenses matching a combined query builder (for admin list + CSV export).
     *
     * Supports: filters (status/tier/product AND), search (email/key substring),
     * expiring window, pagination.
     *
     * This method will be expanded in tasks 21-22 for admin panel list/export features.
     * Placeholder implementation for now.
     *
     * @param array<string, mixed> $params Query parameters
     * @return License[] Array of licenses
     */
    public function queryForAdmin(array $params): array
    {
        // TODO: Implement full query builder in tasks 21-22
        // For now, return empty array as placeholder
        return [];
    }
}
