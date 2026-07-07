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
 * DeactivateHandler - /deactivate endpoint logic (stages 6-8).
 *
 * Implements deactivation logic:
 * 1. Required fields (license_key, site_hash)
 * 2. Unknown license check
 * 3. Site not found check (including already-deactivated case)
 * 4. Deactivate: set deactivated_at
 * 5. Append deactivation event
 * 6. Compute slots_available: activation_limit - count(non-deactivated)
 * 7. Return slots_available
 *
 * Per Requirements 6.1-6.5 and design.md DeactivateHandler section.
 */
final class DeactivateHandler
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
     * Handle /deactivate request (stages 6-8).
     *
     * @param Request $request Request with parsed JSON body
     * @return Response Success or error response
     */
    public function handle(Request $request): Response
    {
        // Stage 6: Required fields present?
        // Per Requirement 6 AC4
        if (!isset($request->json[FieldNames::LICENSE_KEY])) {
            return ErrorResponder::badRequest(
                'Missing required field: ' . FieldNames::LICENSE_KEY . '. ' .
                'The /deactivate endpoint requires both license_key and site_hash.'
            );
        }

        if (!isset($request->json[FieldNames::SITE_HASH])) {
            return ErrorResponder::badRequest(
                'Missing required field: ' . FieldNames::SITE_HASH . '. ' .
                'The /deactivate endpoint requires both license_key and site_hash.'
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
            return $this->processDeactivation($licenseKey, $siteHash);
        } catch (\Throwable $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $licenseKey,
                'site_hash' => $siteHash,
            ]);
            Logger::error($context);

            return ErrorResponder::internalError(
                'Failed to process deactivation request: an unexpected error occurred. ' .
                'Please try again or contact support.',
                'deactivation_failed'
            );
        }
    }

    /**
     * Process deactivation business logic (stage 8).
     *
     * @param string $licenseKey License key
     * @param string $siteHash Site hash (SHA-256)
     * @return Response Success or error response
     */
    private function processDeactivation(string $licenseKey, string $siteHash): Response
    {
        // Unknown license check
        // Per Requirement 6 AC2
        $license = $this->licenseRepo->findByKey($licenseKey);

        if ($license === null) {
            return ErrorResponder::notFound(
                "Unknown license key: {$licenseKey}. No license found with this key. " .
                "Please check the key and try again.",
                'unknown_license'
            );
        }

        // Site not found check (including already-deactivated case)
        // Per Requirement 6 AC3
        $activation = $this->activationRepo->findActiveByHash($license->id, $siteHash);

        if ($activation === null) {
            return ErrorResponder::notFound(
                "Site not found: site_hash {$siteHash} has no active activation for license {$licenseKey}. " .
                "The site may already be deactivated, or it was never activated.",
                'site_not_found'
            );
        }

        // Deactivate: set deactivated_at
        // Per Requirement 6 AC1
        $now = date('Y-m-d H:i:s', $this->clock->now());
        $this->activationRepo->deactivate($activation->id, $now);

        // Append deactivation event
        // Per Requirement 6 AC1
        $this->eventRepo->append($license->id, 'deactivation', [
            'activation_id' => $activation->id,
            'site_hash' => $siteHash,
            'deactivated_at' => $now,
        ]);

        // Compute slots_available: activation_limit - count(non-deactivated after this deactivation)
        // Per Requirement 6 AC5
        $activeCount = $this->activationRepo->countActiveForLicense($license->id);
        $slotsAvailable = $license->activationLimit - $activeCount;

        // Return slots_available
        return Response::json([
            FieldNames::SLOTS_AVAILABLE => $slotsAvailable,
        ]);
    }
}
