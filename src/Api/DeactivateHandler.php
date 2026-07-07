<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Domain\LicenseKeyGenerator;
use App\Http\Response;
use App\Http\ErrorResponder;
use App\Repository\LicenseRepository;
use App\Repository\ActivationRepository;
use App\Repository\LicenseEventRepository;
use App\Support\Clock;
use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * DeactivateHandler - POST /deactivate endpoint.
 *
 * Deactivates a license from a WordPress site.
 *
 * Flow:
 * 1. Required fields: license_key, site_hash
 * 2. Unknown license → license_not_found
 * 3. Site not found (including already-deactivated) → site_not_activated
 * 4. Deactivate the activation
 * 5. Return slots_available (activation_limit minus active count)
 *
 * Per Requirements 6.1-6.5 and design.md /deactivate section.
 */
final class DeactivateHandler
{
    private LicenseRepository $licenseRepo;
    private ActivationRepository $activationRepo;
    private LicenseEventRepository $eventRepo;
    private LicenseKeyGenerator $keyGenerator;
    private Clock $clock;

    public function __construct(Config $config, Clock $clock)
    {
        $this->licenseRepo = new LicenseRepository($config);
        $this->activationRepo = new ActivationRepository($config);
        $this->eventRepo = new LicenseEventRepository($config);
        $this->keyGenerator = new LicenseKeyGenerator();
        $this->clock = $clock;
    }

    /**
     * Handle the deactivation request.
     *
     * @param array<string, mixed> $payload The validated JSON payload
     * @return Response
     */
    public function handle(array $payload): Response
    {
        // Stage 6: Required field validation
        $licenseKey = $payload[FieldNames::LICENSE_KEY] ?? null;
        $siteHash = $payload[FieldNames::DEACTIVATE_SITE_HASH] ?? null;

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
     * Execute business rules for deactivation.
     */
    private function executeBusinessLogic(string $licenseKey, string $siteHash): Response
    {
        try {
            // 1. Find license
            $license = $this->licenseRepo->findByKey($licenseKey);

            if ($license === null) {
                return ErrorResponder::notFound('license_not_found', 'License key not found');
            }

            // 2. Find activation by site_hash (active only)
            $activation = $this->activationRepo->findActiveByHash($siteHash);

            if ($activation === null) {
                return ErrorResponder::notFound(
                    'site_not_activated',
                    'This site is not currently activated (or already deactivated)'
                );
            }

            // 3. Verify activation belongs to this license
            if ($activation->licenseId !== $license->id) {
                return ErrorResponder::forbidden(
                    'site_not_activated',
                    'This site is not activated for this license'
                );
            }

            // 4. Deactivate
            $now = gmdate('Y-m-d H:i:s', $this->clock->now());
            $this->activationRepo->deactivate($activation->id, $now);

            // Log deactivation event
            $this->eventRepo->append($license->id, 'deactivation', [
                'site_hash' => $siteHash,
                'activation_id' => $activation->id,
                'deactivated_at' => $now,
            ]);

            // 5. Calculate slots available
            $activeCount = $this->activationRepo->countActiveForLicense($license->id);
            $slotsAvailable = $license->activationLimit - $activeCount;

            return Response::json([
                'success' => true,
                'message' => 'Site has been deactivated',
                'slots_available' => $slotsAvailable,
            ]);
        } catch (\RuntimeException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $licenseKey,
            ]);
            Logger::error($context);

            return ErrorResponder::internalError('Failed to deactivate license');
        }
    }
}
