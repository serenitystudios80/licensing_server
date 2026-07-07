<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Config;
use App\Domain\Activation;
use App\Support\ErrorContext;
use App\Support\Logger;
use PDO;
use PDOException;

/**
 * Repository for Activation domain objects.
 *
 * Provides CRUD operations for the `license_activations` table.
 * All queries use prepared statements exclusively.
 *
 * Per Requirements 1.3, 1.4, 1.5, 1.6 and design.md Repository layer section.
 */
final class ActivationRepository
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Find an active (non-deactivated) Activation by site_hash.
     *
     * @return Activation|null Returns null if not found or deactivated.
     * @throws \RuntimeException on database error.
     */
    public function findActiveByHash(string $siteHash): ?Activation
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_activations
                 WHERE site_hash = :site_hash
                   AND deactivated_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute(['site_hash' => $siteHash]);

            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            return Activation::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'site_hash' => $siteHash,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query active Activation by site_hash: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find any Activation by site_hash (including deactivated ones).
     *
     * @return Activation|null Returns null if not found.
     * @throws \RuntimeException on database error.
     */
    public function findAnyByHash(string $siteHash): ?Activation
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_activations
                 WHERE site_hash = :site_hash
                 ORDER BY activated_at DESC
                 LIMIT 1'
            );
            $stmt->execute(['site_hash' => $siteHash]);

            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            return Activation::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'site_hash' => $siteHash,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query Activation by site_hash: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find an Activation by its primary key id.
     *
     * @return Activation|null Returns null if not found.
     * @throws \RuntimeException on database error.
     */
    public function findById(int $id): ?Activation
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_activations WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $id]);

            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            return Activation::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'id' => $id,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query Activation by id: database error',
                0,
                $e
            );
        }
    }

    /**
     * Create a new Activation.
     *
     * @param array{
     *     license_id: int,
     *     site_url: string,
     *     site_hash: string,
     *     activated_at: string,
     *     last_validated_at?: string|null,
     * } $data
     *
     * @return Activation The newly created Activation with its database-assigned id.
     * @throws \RuntimeException on database error.
     */
    public function create(array $data): Activation
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO license_activations (
                    license_id, site_url, site_hash, activated_at, last_validated_at
                ) VALUES (
                    :license_id, :site_url, :site_hash, :activated_at, :last_validated_at
                )'
            );

            $stmt->execute([
                'license_id' => $data['license_id'],
                'site_url' => $data['site_url'],
                'site_hash' => $data['site_hash'],
                'activated_at' => $data['activated_at'],
                'last_validated_at' => $data['last_validated_at'] ?? null,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $created = $this->findById($id);
            if ($created === null) {
                throw new \RuntimeException(
                    'Activation was created but could not be retrieved'
                );
            }

            return $created;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $data['license_id'],
                'site_hash' => $data['site_hash'],
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to create Activation: database error',
                0,
                $e
            );
        }
    }

    /**
     * Reactivate a previously deactivated Activation.
     *
     * Sets deactivated_at to NULL and updates last_validated_at.
     *
     * @return bool True if reactivation succeeded, false if not found or already active.
     * @throws \RuntimeException on database error.
     */
    public function reactivate(int $id, string $reactivatedAt): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE license_activations
                 SET deactivated_at = NULL,
                     last_validated_at = :reactivated_at
                 WHERE id = :id
                   AND deactivated_at IS NOT NULL'
            );

            $stmt->execute([
                'id' => $id,
                'reactivated_at' => $reactivatedAt,
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'id' => $id,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to reactivate Activation: database error',
                0,
                $e
            );
        }
    }

    /**
     * Update activation fields.
     *
     * @param array<string, mixed> $fields Fields to update
     * @return bool True if updated
     * @throws \RuntimeException on database error
     */
    public function update(int $id, array $fields): bool
    {
        if (empty($fields)) {
            return false;
        }

        $setClauses = [];
        $params = ['id' => $id];
        
        foreach ($fields as $key => $value) {
            $setClauses[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $sql = 'UPDATE license_activations SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'id' => $id,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to update Activation: database error',
                0,
                $e
            );
        }
    }

    /**
     * Deactivate an active Activation.
     *
     * Sets deactivated_at to the provided timestamp.
     *
     * @return bool True if deactivation succeeded, false if not found or already deactivated.
     * @throws \RuntimeException on database error.
     */
    public function deactivate(int $id, string $deactivatedAt): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE license_activations
                 SET deactivated_at = :deactivated_at
                 WHERE id = :id
                   AND deactivated_at IS NULL'
            );

            $stmt->execute([
                'id' => $id,
                'deactivated_at' => $deactivatedAt,
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'id' => $id,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to deactivate Activation: database error',
                0,
                $e
            );
        }
    }

    /**
     * Count active (non-deactivated) Activations for a License.
     *
     * @throws \RuntimeException on database error.
     */
    public function countActiveForLicense(int $licenseId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM license_activations
                 WHERE license_id = :license_id
                   AND deactivated_at IS NULL'
            );
            $stmt->execute(['license_id' => $licenseId]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to count active Activations: database error',
                0,
                $e
            );
        }
    }

    /**
     * Find all Activations for a License (sorted by activated_at descending).
     *
     * @return list<Activation>
     * @throws \RuntimeException on database error.
     */
    public function findAllForLicense(int $licenseId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_activations
                 WHERE license_id = :license_id
                 ORDER BY activated_at DESC'
            );
            $stmt->execute(['license_id' => $licenseId]);

            $rows = $stmt->fetchAll();
            return array_map(fn($row) => Activation::fromRow($row), $rows);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
            ]);
            Logger::error($context);

            throw new \RuntimeException(
                'Failed to query Activations for License: database error',
                0,
                $e
            );
        }
    }
}
