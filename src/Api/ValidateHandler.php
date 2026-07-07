<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Domain\LicenseKeyGenerator;
use App\Domain\StatusCalculator;
use App\Domain\StatusTransitionApplier;
use App\Http\Response;
use App\Http\ErrorResponder;
use App\Repository\LicenseRepository;
use App\Repository\ActivationRepository;
use App\Repository\LicenseEventRepository;
use App\Support\Clock;
use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * ValidateHandler - POST /validate endpoint.
 *
 * Validates a license and optionally performs Lazy_Check (status transition
 * for annual active/grace licenses).
 *
 * Flow:
 * 1. Required fields: license_key, site_hash
 * 2. Unknown license → license_not_found
 * 3. Site not found (site_hash lookup) → site_not_activated
 * 4. Lazy_Check for annual active/grace licenses
 * 5. Update last_validated_at
 * 6. Return status and expires_at
 *
 * Per Requirements 5.1-5.9 and design.md /validate section.
 */
final class ValidateHandler
{
    private LicenseRepository $licenseRepo;
    private ActivationRepository $activationRepo;
    private LicenseEventRepository $eventRepo;
    private LicenseKeyGenerator $keyGenerator;
    private StatusTransitionApplier $transitionApplier;
    private Clock $clock;

    public function __construct(Config $config, Clock $clock)
    {
        $this->licenseRepo = new LicenseRepository($config);
        $this->activationRepo = new ActivationRepository($config);
        $this->eventRepo = new LicenseEventRepository($config);
        $this->keyGenerator = new LicenseKeyGenerator();
        $this->transitionApplier = new StatusTransitionApplier(
            $this->licenseRepo,
            $this->eventRepo
        );
        $this->clock = $clock;
    }

    /**
     * Handle the validation request.
     *
     * @param array<string, mixed> $payload The validated JSON payload
     * @return Response
     */
    public function handle(array $payload): Response
    {
        // Stage 6: Required field validation
        $licenseKey = $payload[FieldNames::LICENSE_KEY] ?? null;
        $siteHash = $payload[FieldNames::VALIDATE_SITE_HASH] ?? null;

        if ($licenseKey === null || $licenseKey === '') {
            return ErrorResponder::badRequest('missing_license_key', 'Missing required field: license_key');
        }

        if ($siteHash === null || $siteHash === '') {
            return ErrorResponder::badRequest('missing_site_hash', 'Missing required field: site_hash');
        }

        // Stage 7: Field format validation
        if (!$this->keyGenerator->isValidFormat($licenseKey)) {
            return ErrorResponder::badRequest(
                'invalid_license_key_format',
                'Invalid license_key format'
            );
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $siteHash)) {
            return ErrorResponder::badRequest(
                'invalid_site_hash',
                'Invalid site_hash format (expected 64-char hex)'
            );
        }

        // Stage 8: Business rules
        return $this->executeBusinessLogic($licenseKey, $siteHash);
    }

    /**
     * Execute business rules for validation.
     */
    private function executeBusinessLogic(string $licenseKey, string $siteHash): Response
    {
        try {
            // 1. Find license
            $license = $this->licenseRepo->findByKey($licenseKey);

            if ($license === null) {
                return ErrorResponder::notFound('license_not_found', 'License key not found');
            }

            // 2. Find activation by site_hash
            $activation = $this->activationRepo->findActiveByHash($siteHash);

            if ($activation === null) {
                return ErrorResponder::forbidden(
                    'site_not_activated',
                    'This site is not activated for this license'
                );
            }

            // 3. Verify activation belongs to this license
            if ($activation->licenseId !== $license->id) {
                return ErrorResponder::forbidden(
                    'site_not_activated',
                    'This site is not activated for this license'
                );
            }

            // 4. Lazy_Check: Only for annual, non-revoked licenses in active/grace status
            if ($license->tier === 'annual' && 
                $license->status !== 'revoked' && 
                in_array($license->status, ['active', 'grace'], true)) {
                
                $now = $this->clock->now();
                $computation = StatusCalculator::compute($license, $now);

                if ($computation->changed) {
                    // Apply status transition
                    $this->transitionApplier->apply(
                        $license->id,
                        $computation,
                        'silent_lapse_grace' // Event type for Lazy_Check
                    );

                    // Refetch license to get updated status
                    $license = $this->licenseRepo->findById($license->id);
                }
            }

            // 5. Update last_validated_at on activation
            $now = gmdate('Y-m-d H:i:s', $this->clock->now());
            
            // Update activation's last_validated_at
            // Note: We need to update the activation, not through LicenseRepository
            $stmt = $this->activationRepo->update($activation->id, [
                'last_validated_at' => $now,
            ]);

            // 6. Return current status
            return Response::json([
                'success' => true,
                'status' => $license->status,
                'expires_at' => $license->expiresAt,
                'tier' => $license->tier,
            ]);
        } catch (\RuntimeException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $licenseKey,
            ]);
            Logger::error($context);

            return ErrorResponder::internalError('Failed to validate license');
        }
    }
}
