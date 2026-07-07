<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Domain\LicenseKeyGenerator;
use App\Domain\StatusCalculator;
use App\Domain\StatusTransitionApplier;
use App\Http\Request;
use App\Http\Response;
use App\Http\ErrorResponder;
use App\Repository\LicenseRepository;
use App\Repository\ActivationRepository;
use App\Repository\LicenseEventRepository;
use App\Support\Clock;
use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * ValidateHandler - /validate endpoint logic (stages 6-8).
 *
 * Implements validation with Lazy_Check for status computation:
 * 1. Required fields (license_key, site_hash)
 * 2. Unknown license check
 * 3. Site not found check (before any mutation)
 * 4. Lazy_Check: compute true status via StatusCalculator
 * 5. Apply status transition if changed (self-healing via StatusTransitionApplier)
 * 6. Update last_validated_at
 * 7. Return status + expires_at (exactly matching stored/corrected values)
 *
 * Per Requirements 5.1-5.9 and design.md ValidateHandler section.
 */
final class ValidateHandler
{
    private LicenseRepository $licenseRepo;
    private ActivationRepository $activationRepo;
    private LicenseEventRepository $eventRepo;
    private LicenseKeyGenerator $keyGenerator;
    private StatusTransitionApplier $transitionApplier;
    private Clock $clock;

    public function __construct(
        Config $config,
        Clock $clock,
    ) {
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
     * Handle /validate request (stages 6-8).
     *
     * @param Request $request Request with parsed JSON body
     * @return Response Success or error response
     */
    public function handle(Request $request): Response
    {
        // Stage 6: Required fields present?
        // Per Requirement 5 AC4
        if (!isset($request->json[FieldNames::LICENSE_KEY])) {
            return ErrorResponder::badRequest(
                'Missing required field: ' . FieldNames::LICENSE_KEY . '. ' .
                'The /validate endpoint requires both license_key and site_hash.'
            );
        }

        if (!isset($request->json[FieldNames::SITE_HASH])) {
            return ErrorResponder::badRequest(
                'Missing required field: ' . FieldNames::SITE_HASH . '. ' .
                'The /validate endpoint requires both license_key and site_hash.'
            );
        }

        $licenseKey = (string) $request->json[FieldNames::LICENSE_KEY];
        $siteHash = (string) $request->json[FieldNames::SITE_HASH];

        // Stage 7: Field format validation
        if (!$this->keyGenerator->isValidFormat($licenseKey)) {
            return ErrorResponder::badRequest(
                'Invalid license_key format: must match SERB-XXXXX-XXXXX-XXXXX-XXXXX pattern. ' .
                "Received: {$licenseKey}",
                'invalid_license_key_format'
            );
        }

        // Validate site_hash format (64-char lowercase hex)
        if (!preg_match('/^[a-f0-9]{64}$/', $siteHash)) {
            return ErrorResponder::badRequest(
                'Invalid site_hash format: must be a 64-character lowercase hexadecimal SHA-256 digest. ' .
                "Received: {$siteHash}",
                'invalid_site_hash_format'
            );
        }

        // Stage 8: Business rules
        try {
            return $this->processValidation($licenseKey, $siteHash);
        } catch (\Throwable $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $licenseKey,
                'site_hash' => $siteHash,
            ]);
            Logger::error($context);

            return ErrorResponder::internalError(
                'Failed to process validation request: an unexpected error occurred. ' .
                'Please try again or contact support.',
                'validation_failed'
            );
        }
    }

    /**
     * Process validation business logic (stage 8).
     *
     * @param string $licenseKey License key
     * @param string $siteHash Site hash (SHA-256)
     * @return Response Success or error response
     */
    private function processValidation(string $licenseKey, string $siteHash): Response
    {
        // Unknown license check
        // Per Requirement 5 AC2
        $license = $this->licenseRepo->findByKey($licenseKey);

        if ($license === null) {
            return ErrorResponder::notFound(
                "Unknown license key: {$licenseKey}. No license found with this key. " .
                "Please check the key and try again.",
                'unknown_license'
            );
        }

        // Site not found check (BEFORE any mutation)
        // Per Requirement 5 AC3
        $activation = $this->activationRepo->findActiveByHash($license->id, $siteHash);

        if ($activation === null) {
            return ErrorResponder::notFound(
                "Site not found: site_hash {$siteHash} has no active activation for license {$licenseKey}. " .
                "Please activate this site first using the /activate endpoint.",
                'site_not_found'
            );
        }

        // Lazy_Check: compute true status via StatusCalculator
        // Per Requirement 5 AC5, AC6, AC7
        // Only perform Lazy_Check for annual licenses with active/grace status
        // (lifetime and revoked are excluded per StatusCalculator::shouldExclude)
        if (!StatusCalculator::shouldExclude($license)) {
            $statusComputation = StatusCalculator::compute($license, $this->clock->now());

            // If status changed, persist it (self-healing)
            // Per Requirement 11 AC1: Lazy_Check persists corrected status
            if ($statusComputation->changed) {
                $this->transitionApplier->apply($license, $statusComputation, 'lazy_check');

                // Refresh license to get updated status/grace_start_at
                $license = $this->licenseRepo->findById($license->id);

                if ($license === null) {
                    // Should never happen, but handle gracefully
                    return ErrorResponder::internalError(
                        'Failed to retrieve license after status transition.',
                        'internal_error'
                    );
                }
            }
        }

        // Update last_validated_at
        // Per Requirement 5 AC1
        $now = date('Y-m-d H:i:s', $this->clock->now());
        $this->activationRepo->updateLastValidated($activation->id, $now);

        // Build response with status and expires_at
        // Per Requirement 5 AC8: values must EXACTLY match stored (or Lazy_Check-corrected) values
        // Per Requirement 5 AC9: revoked licenses still respond (with status revoked)
        return Response::json([
            FieldNames::STATUS => $license->status,
            FieldNames::EXPIRES_AT => $license->expiresAt,
        ]);
    }
}
