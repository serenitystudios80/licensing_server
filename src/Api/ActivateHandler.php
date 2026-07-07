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
 * ActivateHandler - POST /activate endpoint.
 *
 * Implements stages 6-8 for /activate:
 * - Stage 6: Required fields (license_key, site_url)
 * - Stage 7: Field format validation
 * - Stage 8: Business rules (license status, activation limit, dedup/reactivation)
 *
 * Fixed sub-order per Requirement 4 AC11:
 * 1. Unknown license → license_not_found
 * 2. Revoked status → license_revoked
 * 3. Expired status → license_expired
 * 4. Activation limit check
 * 5. Dedup/reactivation logic
 *
 * Per Requirements 4.1-4.11 and design.md /activate section.
 */
final class ActivateHandler
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
     * Handle the activation request.
     *
     * @param array<string, mixed> $payload The validated JSON payload
     * @return Response
     */
    public function handle(array $payload): Response
    {
        // Stage 6: Required field validation
        $licenseKey = $payload[FieldNames::LICENSE_KEY] ?? null;
        $siteUrl = $payload[FieldNames::SITE_URL] ?? null;

        if ($licenseKey === null || $licenseKey === '') {
            return ErrorResponder::badRequest('missing_license_key', 'Missing required field: license_key');
        }

        if ($siteUrl === null || $siteUrl === '') {
            return ErrorResponder::badRequest('missing_site_url', 'Missing required field: site_url');
        }

        // Stage 7: Field format validation
        if (!$this->keyGenerator->isValidFormat($licenseKey)) {
            return ErrorResponder::badRequest(
                'invalid_license_key_format',
                'Invalid license_key format. Expected: SERB-XXXXX-XXXXX-XXXXX-XXXXX'
            );
        }

        if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            return ErrorResponder::badRequest('invalid_site_url', 'Invalid site_url format');
        }

        // Stage 8: Business rules
        return $this->executeBusinessLogic($licenseKey, $siteUrl);
    }

    /**
     * Execute business rules for activation.
     *
     * Fixed order per Requirement 4 AC11.
     */
    private function executeBusinessLogic(string $licenseKey, string $siteUrl): Response
    {
        try {
            // 1. Find license
            $license = $this->licenseRepo->findByKey($licenseKey);

            if ($license === null) {
                return ErrorResponder::notFound('license_not_found', 'License key not found');
            }

            // 2. Check revoked status
            if ($license->status === 'revoked') {
                return ErrorResponder::forbidden('license_revoked', 'This license has been revoked');
            }

            // 3. Check expired status
            if ($license->status === 'expired') {
                return ErrorResponder::forbidden('license_expired', 'This license has expired');
            }

            // 4. Generate site_hash
            $siteHash = hash('sha256', strtolower(trim($siteUrl)));

            // 5. Check for existing activation (dedup/mismatch/reactivation)
            $existingActivation = $this->activationRepo->findAnyByHash($siteHash);

            if ($existingActivation !== null) {
                // Dedup: Same site already activated with same license
                if ($existingActivation->licenseId === $license->id) {
                    if ($existingActivation->deactivatedAt === null) {
                        // Already activated and not deactivated - return existing activation
                        return Response::json([
                            'success' => true,
                            'activation_id' => $existingActivation->id,
                            'status' => $license->status,
                            'expires_at' => $license->expiresAt,
                            'message' => 'Site is already activated with this license',
                        ]);
                    } else {
                        // Previously deactivated - reactivate it
                        $now = gmdate('Y-m-d H:i:s', $this->clock->now());
                        $this->activationRepo->reactivate($existingActivation->id, $now);

                        // Log reactivation event
                        $this->eventRepo->append($license->id, 'reactivation', [
                            'site_url' => $siteUrl,
                            'site_hash' => $siteHash,
                            'activation_id' => $existingActivation->id,
                        ]);

                        return Response::json([
                            'success' => true,
                            'activation_id' => $existingActivation->id,
                            'status' => $license->status,
                            'expires_at' => $license->expiresAt,
                            'message' => 'Site has been reactivated',
                        ]);
                    }
                } else {
                    // Mismatch: Same site trying to activate with different license
                    return ErrorResponder::forbidden(
                        'site_already_activated',
                        'This site is already activated with a different license'
                    );
                }
            }

            // 6. Check activation limit
            $activeCount = $this->activationRepo->countActiveForLicense($license->id);

            if ($activeCount >= $license->activationLimit) {
                return ErrorResponder::forbidden(
                    'activation_limit_reached',
                    "Activation limit reached ({$license->activationLimit} sites max). Please deactivate a site first."
                );
            }

            // 7. Create new activation
            $now = gmdate('Y-m-d H:i:s', $this->clock->now());
            $activation = $this->activationRepo->create([
                'license_id' => $license->id,
                'site_url' => $siteUrl,
                'site_hash' => $siteHash,
                'activated_at' => $now,
                'last_validated_at' => $now,
            ]);

            // Log activation event
            $this->eventRepo->append($license->id, 'activation', [
                'site_url' => $siteUrl,
                'site_hash' => $siteHash,
                'activation_id' => $activation->id,
            ]);

            return Response::json([
                'success' => true,
                'activation_id' => $activation->id,
                'status' => $license->status,
                'expires_at' => $license->expiresAt,
            ]);
        } catch (\RuntimeException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'license_key' => $licenseKey,
            ]);
            Logger::error($context);

            return ErrorResponder::internalError('Failed to activate license');
        }
    }
}
