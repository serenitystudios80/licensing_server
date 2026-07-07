<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Config;
use App\Domain\License;
use App\Domain\LicenseKeyGenerator;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;
use PDOException;

/**
 * Repository for License domain objects.
 *
 * Provides CRUD operations and query helpers for the `licenses` table.
 * All queries use prepared statements exclusively.
 *
 * Enforces the lifetime-tier constraint: tier 'lifetime' implies expires_at = NULL
 * at creation and update time.
 *
 * Per Requirements 1.1, 1.2, 1.9, 1.10 and design.md Repository layer section.
 */
final class LicenseRepository
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Find a License by its unique license_key.
     *
     * @return License|null Returns null if not found.
     * @throws \RuntimeException on database error (with specific diagnostic context).
     */
    public function findByKey(string $licenseKey): ?License
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM licenses WHERE license_key = :license_key LIMIT 1'
            );
            $stmt->execute(['license_key' => $licenseKey]);

            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            return License::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $licenseKey,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query License by license_key: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find a License by its primary key id.
     *
     * @return License|null Returns null if not found.
     * @throws \RuntimeException on database error.
     */
    public function findById(int $id): ?License
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM licenses WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);

            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            return License::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'id' => $id,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query License by id: database error',
                0,
                $e
            );
        }
    }

    /**
     * Create a new License.
     *
     * Generates a unique license_key if not provided. Enforces the lifetime-tier
     * constraint: if tier is 'lifetime', expires_at is forced to NULL regardless
     * of what the caller passes.
     *
     * @param array{
     *     license_key?: string,
     *     email: string,
     *     customer_name: string,
     *     product: string,
     *     tier: string,
     *     status: string,
     *     purchased_at: string,
     *     expires_at: string|null,
     *     grace_start_at?: string|null,
     *     razorpay_subscription_id?: string|null,
     *     activation_limit: int,
     *     price_amount?: string|null,
     *     currency?: string|null,
     *     notes?: string,
     * } $data
     *
     * @return License The newly created License with its database-assigned id.
     * @throws \RuntimeException on database error (including unique constraint violation).
     */
    public function create(array $data): License
    {
        // Generate unique license_key if not provided
        $licenseKey = $data['license_key'] ?? $this->generateUniqueLicenseKey();

        // Enforce lifetime-tier constraint
        $tier = $data['tier'];
        $expiresAt = ($tier === 'lifetime') ? null : ($data['expires_at'] ?? null);

        $insertData = [
            'license_key' => $licenseKey,
            'email' => $data['email'],
            'customer_name' => $data['customer_name'],
            'product' => $data['product'],
            'tier' => $tier,
            'status' => $data['status'],
            'purchased_at' => $data['purchased_at'],
            'expires_at' => $expiresAt,
            'grace_start_at' => $data['grace_start_at'] ?? null,
            'razorpay_subscription_id' => $data['razorpay_subscription_id'] ?? null,
            'activation_limit' => $data['activation_limit'],
            'price_amount' => $data['price_amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'notes' => $data['notes'] ?? '',
        ];

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO licenses (
                    license_key, email, customer_name, product, tier, status,
                    purchased_at, expires_at, grace_start_at, razorpay_subscription_id,
                    activation_limit, price_amount, currency, notes
                ) VALUES (
                    :license_key, :email, :customer_name, :product, :tier, :status,
                    :purchased_at, :expires_at, :grace_start_at, :razorpay_subscription_id,
                    :activation_limit, :price_amount, :currency, :notes
                )'
            );

            $stmt->execute($insertData);

            $id = (int) $this->pdo->lastInsertId();

            // Fetch and return the complete License object
            $created = $this->findById($id);
            if ($created === null) {
                throw new \RuntimeException(
                    'License was created but could not be retrieved'
                );
            }

            return $created;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'email' => $data['email'],
                'product' => $data['product'],
                'tier' => $tier,
                // Never log the full license_key in error context for security
            ]);
            Logger::error($context);

            // Check for unique constraint violation on license_key
            if ($e->getCode() === '23000') {
                throw new \RuntimeException(
                    'Failed to create License: duplicate license_key',
                    0,
                    $e
                );
            }

            throw new \RuntimeException(
                'Failed to create License: database error',
                0,
                $e
            );
        }
    }

    /**
     * Update specific fields of an existing License.
     *
     * Only the fields present in $fields are updated. Enforces the lifetime-tier
     * constraint: if tier is being set to 'lifetime', expires_at is forced to NULL.
     *
     * @param array<string, mixed> $fields Field => value map. Allowed keys are a
     *        subset of License properties (no id/license_key/created_at updates).
     * @param array<string, mixed> $whereConditions Optional WHERE conditions (key => value).
     *        If empty, only the $id is used. Used for optimistic locking (e.g., WHERE status = 'active').
     *
     * @return bool True if at least one row was updated, false otherwise.
     * @throws \RuntimeException on database error.
     */
    public function updateFields(int $id, array $fields, array $whereConditions = []): bool
    {
        if (empty($fields)) {
            return false;
        }

        // Enforce lifetime-tier constraint during update
        if (isset($fields['tier']) && $fields['tier'] === 'lifetime') {
            $fields['expires_at'] = null;
        }

        // Build SET clause
        $setClauses = [];
        $params = [];
        foreach ($fields as $key => $value) {
            $setClauses[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        // Build WHERE clause
        $whereClauses = ['id = :id'];
        $params['id'] = $id;

        foreach ($whereConditions as $key => $value) {
            $whereClauses[] = "{$key} = :where_{$key}";
            $params["where_{$key}"] = $value;
        }

        $sql = sprintf(
            'UPDATE licenses SET %s WHERE %s',
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'id' => $id,
                'fields' => array_keys($fields),
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to update License fields: database error',
                0,
                $e
            );
        }
    }

    /**
     * Search Licenses by email or license_key substring (case-insensitive).
     *
     * @return list<License>
     */
    public function search(string $query, int $limit = 50, int $offset = 0): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM licenses
                 WHERE LOWER(email) LIKE LOWER(:query)
                    OR LOWER(license_key) LIKE LOWER(:query)
                 ORDER BY created_at DESC
                 LIMIT :limit OFFSET :offset'
            );

            $stmt->bindValue('query', "%{$query}%", PDO::PARAM_STR);
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            return array_map(fn($row) => License::fromRow($row), $rows);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to search Licenses: database error',
                0,
                $e
            );
        }
    }

    /**
     * Filter Licenses by allowed criteria with pagination.
     *
     * @param array<string, mixed> $filters Allowed keys: status, tier, product
     * @return list<License>
     */
    public function filter(array $filters, int $limit = 50, int $offset = 0): array
    {
        $allowedKeys = ['status', 'tier', 'product'];
        $whereClauses = [];
        $params = [];

        foreach ($filters as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                $whereClauses[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }

        $whereClause = empty($whereClauses) ? '1=1' : implode(' AND ', $whereClauses);

        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM licenses
                 WHERE {$whereClause}
                 ORDER BY created_at DESC
                 LIMIT :limit OFFSET :offset"
            );

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            return array_map(fn($row) => License::fromRow($row), $rows);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to filter Licenses: database error',
                0,
                $e
            );
        }
    }

    /**
     * Count Licenses by specific criteria.
     *
     * @param array<string, mixed> $filters
     */
    public function countBy(array $filters): int
    {
        $allowedKeys = ['status', 'tier', 'product'];
        $whereClauses = [];
        $params = [];

        foreach ($filters as $key => $value) {
            if (in_array($key, $allowedKeys, true)) {
                $whereClauses[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }

        $whereClause = empty($whereClauses) ? '1=1' : implode(' AND ', $whereClauses);

        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM licenses WHERE {$whereClause}");
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to count Licenses: database error',
                0,
                $e
            );
        }
    }

    /**
     * Count Licenses by status.
     */
    public function countByStatus(string $status): int
    {
        return $this->countBy(['status' => $status]);
    }

    /**
     * Count Licenses by tier.
     */
    public function countByTier(string $tier): int
    {
        return $this->countBy(['tier' => $tier]);
    }

    /**
     * Calculate Monthly Recurring Revenue (MRR).
     * 
     * MRR = Sum of price_amount for active annual INR licenses / 12
     */
    public function calculateMRR(): float
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(CAST(price_amount AS DECIMAL(10,2))), 0) as total
                 FROM licenses
                 WHERE status = :status
                   AND tier = :tier
                   AND currency = :currency
                   AND price_amount IS NOT NULL'
            );

            $stmt->execute([
                'status' => 'active',
                'tier' => 'annual',
                'currency' => 'INR',
            ]);

            $row = $stmt->fetch();
            $totalAnnual = (float) ($row['total'] ?? 0);

            // MRR is annual amount divided by 12
            return $totalAnnual / 12;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to calculate MRR: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find Licenses expiring within a given number of days.
     *
     * @param int $days Number of days from now
     * @return list<License>
     */
    public function expiringWithin(int $days): array
    {
        $days = max(1, min(365, $days)); // Clamp 1-365 days

        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM licenses
                 WHERE tier = :tier
                   AND status IN (:status_active, :status_grace)
                   AND expires_at IS NOT NULL
                   AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days DAY)
                 ORDER BY expires_at ASC'
            );

            $stmt->execute([
                'tier' => 'annual',
                'status_active' => 'active',
                'status_grace' => 'grace',
                'days' => $days,
            ]);

            $rows = $stmt->fetchAll();
            return array_map(fn($row) => License::fromRow($row), $rows);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query expiring Licenses: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find Licenses expiring within a given number of days.
     *
     * @param int $days Number of days from now
     * @return list<License>
     */
    public function expiringWithin(int $days): array
    {
        $days = max(1, min(365, $days)); // Clamp 1-365 days

        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM licenses
                 WHERE tier = :tier
                   AND status IN (:status_active, :status_grace)
                   AND expires_at IS NOT NULL
                   AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days DAY)
                 ORDER BY expires_at ASC'
            );

            $stmt->execute([
                'tier' => 'annual',
                'status_active' => 'active',
                'status_grace' => 'grace',
                'days' => $days,
            ]);

            $rows = $stmt->fetchAll();
            return array_map(fn($row) => License::fromRow($row), $rows);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query expiring Licenses: database error',
                0,
                $e
            );
        }
    }

    /**
     * Generate a unique license key that doesn't exist in the database.
     *
     * Retries up to 10 times to handle the unlikely collision case.
     *
     * @throws \RuntimeException if unable to generate a unique key after max retries.
     */
    private function generateUniqueLicenseKey(): string
    {
        $maxRetries = 10;
        $generator = new LicenseKeyGenerator();

        for ($i = 0; $i < $maxRetries; $i++) {
            $key = $generator->generate();

            // Check if key already exists
            $existing = $this->findByKey($key);
            if ($existing === null) {
                return $key;
            }
        }

        throw new \RuntimeException(
            'Failed to generate unique license_key after ' . $maxRetries . ' attempts'
        );
    }
}
