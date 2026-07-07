<?php

declare(strict_types=1);

namespace App\Admin;

use App\Config\Config;
use App\Repository\LicenseRepository;
use App\Support\Clock;
use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * DashboardController - Admin dashboard data aggregation.
 *
 * Computes and provides dashboard metrics (Requirement 14):
 * - Active license count (14.1)
 * - Monthly Recurring Revenue (MRR) (14.2)
 * - Non-revoked lifetime license count (14.3)
 * - Licenses expiring within 7 days (14.4)
 * - Licenses in grace status (14.5)
 *
 * Per Requirements 14.1-14.5 and design.md Admin section.
 */
final class DashboardController
{
    private const EXPIRING_SOON_HOURS = 168; // 7 days

    private LicenseRepository $licenseRepo;
    private Clock $clock;

    public function __construct(
        Config $config,
        LicenseRepository $licenseRepo,
        Clock $clock,
    ) {
        $this->licenseRepo = $licenseRepo;
        $this->clock = $clock;
    }

    /**
     * Get dashboard metrics.
     *
     * Returns an associative array with all dashboard data:
     * - activeCount: Count of active licenses (Requirement 14.1)
     * - mrr: Monthly Recurring Revenue in INR (Requirement 14.2)
     * - lifetimeCount: Count of non-revoked lifetime licenses (Requirement 14.3)
     * - expiringSoon: Array of licenses expiring within 7 days (Requirement 14.4)
     * - graceStatus: Array of licenses in grace status (Requirement 14.5)
     *
     * @return array<string, mixed>
     */
    public function getDashboardData(): array
    {
        try {
            return [
                'activeCount' => $this->getActiveCount(),
                'mrr' => $this->calculateMRR(),
                'lifetimeCount' => $this->getLifetimeCount(),
                'expiringSoon' => $this->getExpiringSoon(),
                'graceStatus' => $this->getGraceStatus(),
            ];
        } catch (\Exception $e) {
            $context = ErrorContext::describe($e, ['method' => __METHOD__]);
            Logger::error($context);
            
            throw new \RuntimeException(
                "Failed to load dashboard data: {$e->getMessage()}. Check logs for details.",
                0,
                $e
            );
        }
    }

    /**
     * Get count of active licenses (Requirement 14.1).
     *
     * @return int Active license count
     */
    private function getActiveCount(): int
    {
        return $this->licenseRepo->countByStatus('active');
    }

    /**
     * Calculate Monthly Recurring Revenue (Requirement 14.2).
     *
     * MRR = Sum of price_amount for (status=active AND tier=annual AND currency=INR AND price_amount NOT NULL) / 12
     *
     * @return float MRR in INR
     */
    private function calculateMRR(): float
    {
        return $this->licenseRepo->calculateMRR();
    }

    /**
     * Get count of non-revoked lifetime licenses (Requirement 14.3).
     *
     * Counts licenses WHERE tier='lifetime' AND status != 'revoked'.
     *
     * @return int Non-revoked lifetime license count
     */
    private function getLifetimeCount(): int
    {
        // Get total lifetime licenses
        $totalLifetime = $this->licenseRepo->countByTier('lifetime');
        
        // Subtract revoked lifetime licenses
        $revokedLifetime = $this->getRevokedLifetimeCount();
        
        return $totalLifetime - $revokedLifetime;
    }

    /**
     * Get count of revoked lifetime licenses (helper for getLifetimeCount).
     *
     * @return int Revoked lifetime license count
     */
    private function getRevokedLifetimeCount(): int
    {
        // This requires a combined filter - we'll implement a helper query
        // For now, fetch all revoked and filter in PHP (can optimize later)
        $filters = ['tier' => 'lifetime', 'status' => 'revoked'];
        $revokedLifetime = $this->licenseRepo->filter($filters);
        
        return count($revokedLifetime);
    }

    /**
     * Get licenses expiring within 7 days (Requirement 14.4).
     *
     * Returns licenses WHERE status='active' AND expires_at BETWEEN now AND now+168h.
     * Each entry includes: license_key, email, expires_at.
     *
     * @return array<int, array{license_key: string, email: string, expires_at: string|null, customer_name: string, id: int}>
     */
    private function getExpiringSoon(): array
    {
        $days = (int) ceil(self::EXPIRING_SOON_HOURS / 24); // 168h = 7 days
        $licenses = $this->licenseRepo->expiringWithin($days);
        
        // Map to simpler array structure with required fields
        return array_map(function ($license) {
            return [
                'id' => $license->id,
                'license_key' => $license->licenseKey,
                'email' => $license->email,
                'customer_name' => $license->customerName,
                'expires_at' => $license->expiresAt,
            ];
        }, $licenses);
    }

    /**
     * Get licenses in grace status (Requirement 14.5).
     *
     * Returns licenses WHERE status='grace'.
     * Each entry includes: license_key, email, expires_at.
     *
     * @return array<int, array{license_key: string, email: string, expires_at: string|null, customer_name: string, id: int, grace_start_at: string|null}>
     */
    private function getGraceStatus(): array
    {
        $filters = ['status' => 'grace'];
        $licenses = $this->licenseRepo->filter($filters);
        
        // Map to simpler array structure with required fields
        return array_map(function ($license) {
            return [
                'id' => $license->id,
                'license_key' => $license->licenseKey,
                'email' => $license->email,
                'customer_name' => $license->customerName,
                'expires_at' => $license->expiresAt,
                'grace_start_at' => $license->graceStartAt,
            ];
        }, $licenses);
    }
}
