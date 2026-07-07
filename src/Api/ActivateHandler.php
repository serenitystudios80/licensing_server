<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Domain\LicenseKeyGenerator;
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
 * ActivateHandler - /activate endpoint logic (stages 6-8).
 *
 * Implements the fixed sub-order from Requirement 4 AC11:
 * 1. Missing required field (license_key or site_url)
 * 2. license_key format validation
 * 3. Unknown license_key (database lookup)
 * 4. License status revoked/expired
 * 5. Activation limit exceeded
 *
 * Then performs activation logic:
 * - Idempotent dedup: existing active site_hash → update last_validated_at, return current status
 * - Reactivation: existing deactivated site_hash → clear deactivated_at, update timestamps
 * - New activation: create new activation row, append event
 *
 * Per Requirements 4.1-4.11 and design.md ActivateHandler section.
 */
final class ActivateHandler
{
    private LicenseRepository $licenseRepo;
    private ActivationRepository $activationRepo;
    private LicenseEventRepository $eventRepo;
    private LicenseKeyGenerator $keyGenerator;
    private Clock $clock;

    public function __construct(
        Config $config,
        Clock $clock,
    ) {
        $this->licenseRepo = new LicenseRepository($config);
        $this->activationRepo = new ActivationRepository($config);
        $this->eventRepo = new LicenseEventRepository($config);
        $this->keyGenerator = new LicenseKeyGenerator();
        $this->clock = $clock;
    }

    /**
     * Handle /activate request (stages 6-8).
     *
     * @param Request $request Request with parsed JSON body
     * @return Response Success or error response
     */
    public function handle(Request $request): Response
    {
        // Stage 6: Required fields present?
        // Per Requirement 4 AC4, AC11 (sub-order #1)
        if (!isset($request->json[FieldNames::LICENSE_KEY])) {
            return ErrorResponder::badRequest(
                'Missing required field: ' . FieldNames::LICENSE_KEY . '. ' .
                'The /activate endpoint requires both license_key and site_url.'
            );
        }

        if (!isset($request->json[FieldNames::SITE_URL])) {
            return ErrorResponder::badRequest(
                'Missing required field: ' . FieldNames::SITE_URL . '. ' .
                'The /activate endpoint requires both license_key and site_url.'
            );
        }

        $licenseKey = (string) $request->json[FieldNames::LICENSE_KEY];
        $siteUrl = (string) $request->json[FieldNames::SITE_URL];

        // Stage 7: Field format validation
        // Per Requirement 4 AC3, AC11 (sub-order #2)
        if (!$this->keyGenerator->isValidFormat($licenseKey)) {
            return ErrorResponder::badRequest(
                'Invalid license_key format: must match SERB-XXXXX-XXXXX-XXXXX-XXXXX pattern. ' .
                "Received: {$licenseKey}",
                'invalid_license_key_format'
            );
        }

        // Compute site_hash from site_url (SHA-256)
        // Per Requirement 4 AC1
        $siteHash = hash('sha256', $siteUrl);

        // Stage 8: Business rules
        try {
            return $this->processActivation($licenseKey, $siteUrl, $siteHash);
        } catch (\Throwable $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $licenseKey,
                'site_url' => $siteUrl,
            ]);
            Logger::error($context);

            return ErrorResponder::internalError(
                'Failed to process activation request: an unexpected error occurred. ' .
                'Please try again or contact support.',
                'activation_failed'
            );
        }
    }

    /**
     * Process activation business logic (stage 8).
     *
     * Implements the fixed sub-order from Requirement 4 AC11.
     *
     * @param string $licenseKey License key
     * @param string $siteUrl Site URL (human-readable)
     * @param string $siteHash Site hash (SHA-256 of site_url)
     * @return Response Success or error response
     */
    private function processActivation(string $licenseKey, string $siteUrl, string $siteHash): Response
    {
        // Sub-order #3: Unknown license_key check
        // Per Requirement 4 AC2, AC11
        $license = $this->licenseRepo->findByKey($licenseKey);

        if ($license === null) {
            return ErrorResponder::notFound(
                "Unknown license key: {$licenseKey}. No license found with this key. " .
                "Please check the key and try again.",
                'unknown_license'
            );
        }

        // Sub-order #4: License status revoked/expired check
        // Per Requirement 4 AC9, AC11
        if ($license->status === 'revoked') {
            return ErrorResponder::badRequest(
                "License {$licenseKey} has been revoked and cannot be activated. " .
                "Contact support if you believe this is an error.",
                'license_revoked'
            );
        }

        if ($license->status === 'expired') {
            return ErrorResponder::badRequest(
                "License {$licenseKey} has expired and cannot be activated. " .
                "Please renew your license to continue using Pro features.",
                'license_expired'
            );
        }

        // Check for existing activation (active or deactivated)
        $existingActivation = $this->activationRepo->findAnyByHash($license->id, $siteHash);

        // Idempotent dedup: existing active activation
        // Per Requirement 4 AC5
        if ($existingActivation !== null && $existingActivation->deactivatedAt === null) {
            // Already activated - update last_validated_at and return current status
            $now = date('Y-m-d H:i:s', $this->clock->now());
            $this->activationRepo->updateLastValidated($existingActivation->id, $now);

            return Response::json([
                FieldNames::STATUS => $license->status,
                FieldNames::EXPIRES_AT => $license->expiresAt,
            ]);
        }

        // Sub-order #5: Activation limit exceeded check
        // Per Requirement 4 AC6, AC11
        // Only check if this is a NEW activation (not reactivation)
        $activeCount = $this->activationRepo->countActiveForLicense($license->id);

        if ($activeCount >= $license->activationLimit) {
            return ErrorResponder::badRequest(
                "Activation limit exceeded for license {$licenseKey}. " .
                "You have {$activeCount} active site(s) and your limit is {$license->activationLimit}. " .
                "Please deactivate an existing site before activating a new one.",
                'activation_limit_exceeded'
            );
        }

        // Perform activation: reactivation or new activation
        $now = date('Y-m-d H:i:s', $this->clock->now());

        if ($existingActivation !== null && $existingActivation->deactivatedAt !== null) {
            // Reactivation: existing deactivated activation
            // Per Requirement 4 AC10
            $activation = $this->activationRepo->reactivate($existingActivation->id, $now, $now);
        } else {
            // New activation: no existing activation for this site_hash
            // Per Requirement 4 AC7
            $activation = $this->activationRepo->create($license->id, $siteUrl, $siteHash, $now, $now);
        }

        // Append activation event
        // Per Requirement 4 AC7
        $this->eventRepo->append($license->id, 'activation', [
            'activation_id' => $activation->id,
            'site_url' => $siteUrl,
            'site_hash' => $siteHash,
            'activated_at' => $now,
            'context' => $existingActivation !== null ? 'reactivation' : 'new_activation',
        ]);

        // Return success response with current status and expiry
        // Per Requirement 4 AC8
        return Response::json([
            FieldNames::STATUS => $license->status,
            FieldNames::EXPIRES_AT => $license->expiresAt,
        ]);
    }
}
