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
 * Repository for Activation entities.
 *
 * Provides operations for managing site activations: finding active/deactivated
 * activations, creating new activations, reactivating previously deactivated ones,
 * deactivating active ones, and counting active activations per license.
 *
 * Supports the activation dedup, mismatch handling, and reactivation logic
 * (Requirements 1.4, 1.5, 1.6, Correctness Property 4).
 *
 * All queries use PDO prepared statements exclusively.
 */
final class ActivationRepository
{
    private PDO $pdo;

    public function __construct(Config $config)
    {
        $this->pdo = Db::getConnection($config);
    }

    /**
     * Find a non-deactivated (active) activation by site_hash for a given license.
     *
     * Returns only activations where deactivated_at IS NULL.
     * Used to check if a site is already activated (dedup logic in /activate).
     *
     * @param int $licenseId License ID
     * @param string $siteHash SHA-256 site hash (64-char lowercase hex)
     * @return Activation|null Returns null if no active activation found
     */
    public function findActiveByHash(int $licenseId, string $siteHash): ?Activation
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_activations 
                 WHERE license_id = ? 
                   AND site_hash = ? 
                   AND deactivated_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute([$licenseId, $siteHash]);
            $row = $stmt->fetch();

            return $row ? Activation::fromRow($row) : null;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'site_hash' => $siteHash,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find active activation for license {$licenseId} and site hash {$siteHash}: " .
                "database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Find ANY activation (including deactivated) by site_hash for a given license.
     *
     * Used to find previously deactivated activations for reactivation logic
     * (Requirement 4 AC10).
     *
     * If multiple rows exist (historical data), returns the most recently deactivated one.
     *
     * @param int $licenseId License ID
     * @param string $siteHash SHA-256 site hash (64-char lowercase hex)
     * @return Activation|null Returns null if no activation (active or deactivated) found
     */
    public function findAnyByHash(int $licenseId, string $siteHash): ?Activation
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_activations 
                 WHERE license_id = ? 
                   AND site_hash = ?
                 ORDER BY deactivated_at DESC, activated_at DESC
                 LIMIT 1'
            );
            $stmt->execute([$licenseId, $siteHash]);
            $row = $stmt->fetch();

            return $row ? Activation::fromRow($row) : null;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'site_hash' => $siteHash,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find any activation for license {$licenseId} and site hash {$siteHash}: " .
                "database query error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Create a new activation.
     *
     * Used when a site_hash has never been activated for this license, or when
     * all prior activations for this site_hash are deactivated and we're NOT
     * reusing an existing row (business logic decides whether to call create()
     * or reactivate() based on findAnyByHash() result).
     *
     * @param int $licenseId License ID
     * @param string $siteUrl Human-readable site URL (max 500 chars)
     * @param string $siteHash SHA-256 site hash (64-char lowercase hex)
     * @param string $activatedAt Activation timestamp (DATETIME format)
     * @param string|null $lastValidatedAt Last validation timestamp (optional)
     * @return Activation The created Activation entity
     * @throws \RuntimeException on creation failure
     */
    public function create(
        int $licenseId,
        string $siteUrl,
        string $siteHash,
        string $activatedAt,
        ?string $lastValidatedAt = null
    ): Activation {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO license_activations (
                    license_id, site_url, site_hash, activated_at, last_validated_at
                ) VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $licenseId,
                $siteUrl,
                $siteHash,
                $activatedAt,
                $lastValidatedAt,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            // Fetch the created activation to return full entity
            $stmt = $this->pdo->prepare('SELECT * FROM license_activations WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new \RuntimeException(
                    "Activation created but could not be retrieved. Activation ID: {$id}"
                );
            }

            return Activation::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
                'site_url' => $siteUrl,
                'site_hash' => $siteHash,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to create activation for license {$licenseId} and site hash {$siteHash}: " .
                "database insert error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Reactivate a previously deactivated activation.
     *
     * Clears deactivated_at (sets to NULL) and updates activated_at and
     * last_validated_at to current values. Does NOT create a new row.
     *
     * Per Requirement 4 AC10: when a site_hash matches a previously deactivated
     * activation, reuse that same row rather than inserting a new one.
     *
     * @param int $activationId Activation ID to reactivate
     * @param string $activatedAt New activation timestamp (DATETIME format)
     * @param string|null $lastValidatedAt New last validation timestamp (optional)
     * @return Activation The reactivated Activation entity
     * @throws \RuntimeException on reactivation failure or if activation not found
     */
    public function reactivate(
        int $activationId,
        string $activatedAt,
        ?string $lastValidatedAt = null
    ): Activation {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE license_activations 
                 SET deactivated_at = NULL,
                     activated_at = ?,
                     last_validated_at = ?
                 WHERE id = ?'
            );

            $stmt->execute([
                $activatedAt,
                $lastValidatedAt,
                $activationId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new \RuntimeException(
                    "Failed to reactivate activation: activation ID {$activationId} not found or " .
                    "no changes made. Ensure the activation exists before calling reactivate()."
                );
            }

            // Fetch the reactivated activation to return full entity
            $stmt = $this->pdo->prepare('SELECT * FROM license_activations WHERE id = ? LIMIT 1');
            $stmt->execute([$activationId]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new \RuntimeException(
                    "Activation reactivated but could not be retrieved. Activation ID: {$activationId}"
                );
            }

            return Activation::fromRow($row);
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'activation_id' => $activationId,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to reactivate activation ID {$activationId}: database update error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Deactivate an active activation.
     *
     * Sets deactivated_at to the provided timestamp.
     * Does NOT delete the row (per Requirement 1.7: retain all data indefinitely).
     *
     * Used by /deactivate endpoint (Requirement 6).
     *
     * @param int $activationId Activation ID to deactivate
     * @param string $deactivatedAt Deactivation timestamp (DATETIME format)
     * @return bool True on success, false if no rows affected
     * @throws \RuntimeException on deactivation failure
     */
    public function deactivate(int $activationId, string $deactivatedAt): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE license_activations 
                 SET deactivated_at = ?
                 WHERE id = ? AND deactivated_at IS NULL'
            );

            $stmt->execute([$deactivatedAt, $activationId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'activation_id' => $activationId,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to deactivate activation ID {$activationId}: database update error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Count non-deactivated (active) activations for a given license.
     *
     * Used to enforce activation_limit (Requirement 4 AC6, Correctness Property 5).
     * Also used by /deactivate to compute slots_available response value (Requirement 6 AC5).
     *
     * @param int $licenseId License ID
     * @return int Count of active activations (deactivated_at IS NULL)
     */
    public function countActiveForLicense(int $licenseId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM license_activations 
                 WHERE license_id = ? AND deactivated_at IS NULL'
            );
            $stmt->execute([$licenseId]);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to count active activations for license {$licenseId}: database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Update last_validated_at for an activation.
     *
     * Used by /validate and idempotent /activate requests to touch the
     * last_validated_at timestamp (Requirements 4 AC5, 5 AC1).
     *
     * @param int $activationId Activation ID
     * @param string $lastValidatedAt New last validation timestamp (DATETIME format)
     * @return bool True on success, false if no rows affected
     * @throws \RuntimeException on update failure
     */
    public function updateLastValidated(int $activationId, string $lastValidatedAt): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE license_activations 
                 SET last_validated_at = ?
                 WHERE id = ?'
            );

            $stmt->execute([$lastValidatedAt, $activationId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'activation_id' => $activationId,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to update last_validated_at for activation ID {$activationId}: " .
                "database update error. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Find all activations (active and deactivated) for a license.
     *
     * Used by admin panel license detail view (task 23) to show all historical
     * activations, sorted descending by activated_at.
     *
     * @param int $licenseId License ID
     * @return Activation[] Array of activations
     */
    public function findAllForLicense(int $licenseId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM license_activations 
                 WHERE license_id = ?
                 ORDER BY activated_at DESC'
            );
            $stmt->execute([$licenseId]);

            return array_map(
                fn($row) => Activation::fromRow($row),
                $stmt->fetchAll()
            );
        } catch (PDOException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_id' => $licenseId,
            ]);
            Logger::error($context);
            throw new \RuntimeException(
                "Failed to find all activations for license {$licenseId}: database query error. " .
                "Check logs for details.",
                0,
                $e
            );
        }
    }
}
