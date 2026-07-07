<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Domain\LicenseKeyGenerator;
use App\Http\Response;
use App\Repository\LicenseRepository;
use App\Support\Clock;
use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * Handler for POST /create-license endpoint.
 *
 * This endpoint is called by serenitystudios.in after a successful Razorpay payment
 * to create a new license in this license server.
 *
 * Expected request payload (JSON):
 * {
 *     "email": "customer@example.com",
 *     "customer_name": "John Doe",
 *     "product": "serenity-booking",
 *     "tier": "annual" | "lifetime",
 *     "activation_limit": 1,
 *     "price_amount": "999.00",  // optional, DECIMAL as string
 *     "currency": "INR",  // optional, 3-letter code
 *     "razorpay_subscription_id": "sub_xxx",  // optional
 *     "razorpay_payment_id": "pay_xxx",  // optional, for audit
 *     "notes": "Additional notes"  // optional
 * }
 *
 * Returns:
 * {
 *     "success": true,
 *     "license_key": "SERB-XXXXX-XXXXX-XXXXX-XXXXX",
 *     "license_id": 123,
 *     "status": "active",
 *     "expires_at": "2025-07-07 12:00:00" | null  // null for lifetime licenses
 * }
 */
final class CreateLicenseHandler
{
    private LicenseRepository $licenseRepo;
    private LicenseKeyGenerator $keyGenerator;
    private Clock $clock;

    public function __construct(
        Config $config,
        Clock $clock
    ) {
        $this->licenseRepo = new LicenseRepository($config);
        $this->keyGenerator = new LicenseKeyGenerator();
        $this->clock = $clock;
    }

    /**
     * Handle the license creation request.
     *
     * @param array<string, mixed> $payload The validated JSON payload.
     * @return Response
     */
    public function handle(array $payload): Response
    {
        // Stage 6: Required field validation
        $requiredFields = ['email', 'customer_name', 'product', 'tier', 'activation_limit'];
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                return Response::json([
                    'error_code' => 'missing_field',
                    'message' => "Missing required field: {$field}",
                ], 400);
            }
        }

        // Stage 7: Field format validation
        $email = $payload['email'];
        $customerName = $payload['customer_name'];
        $product = $payload['product'];
        $tier = $payload['tier'];
        $activationLimit = $payload['activation_limit'];

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json([
                'error_code' => 'invalid_email',
                'message' => 'Invalid email format',
            ], 400);
        }

        // Validate tier
        if (!in_array($tier, ['annual', 'lifetime'], true)) {
            return Response::json([
                'error_code' => 'invalid_tier',
                'message' => 'Tier must be "annual" or "lifetime"',
            ], 400);
        }

        // Validate activation_limit
        if (!is_int($activationLimit) || $activationLimit < 1) {
            return Response::json([
                'error_code' => 'invalid_activation_limit',
                'message' => 'Activation limit must be a positive integer',
            ], 400);
        }

        // Validate price_amount and currency (both must be present or both absent)
        $priceAmount = $payload['price_amount'] ?? null;
        $currency = $payload['currency'] ?? null;

        if (($priceAmount !== null && $currency === null) ||
            ($priceAmount === null && $currency !== null)) {
            return Response::json([
                'error_code' => 'invalid_price_data',
                'message' => 'price_amount and currency must be both present or both absent',
            ], 400);
        }

        // Calculate expires_at based on tier
        $now = $this->clock->now();
        $purchasedAt = gmdate('Y-m-d H:i:s', $now);
        $expiresAt = null;

        if ($tier === 'annual') {
            // Annual license expires in 1 year
            $expiresAt = gmdate('Y-m-d H:i:s', strtotime('+1 year', $now));
        }
        // Lifetime licenses have expires_at = NULL

        // Create the license
        try {
            $license = $this->licenseRepo->create([
                'email' => $email,
                'customer_name' => $customerName,
                'product' => $product,
                'tier' => $tier,
                'status' => 'active',
                'purchased_at' => $purchasedAt,
                'expires_at' => $expiresAt,
                'activation_limit' => $activationLimit,
                'price_amount' => $priceAmount,
                'currency' => $currency,
                'razorpay_subscription_id' => $payload['razorpay_subscription_id'] ?? null,
                'notes' => $payload['notes'] ?? '',
            ]);

            // Log successful creation with payment details for audit
            $auditContext = [
                'license_id' => $license->id,
                'license_key' => $license->licenseKey,
                'email' => $email,
                'tier' => $tier,
                'razorpay_payment_id' => $payload['razorpay_payment_id'] ?? null,
            ];
            Logger::info('License created via /create-license API: ' . json_encode($auditContext));

            return Response::json([
                'success' => true,
                'license_key' => $license->licenseKey,
                'license_id' => $license->id,
                'status' => $license->status,
                'expires_at' => $license->expiresAt,
                'activation_limit' => $license->activationLimit,
            ], 201);
        } catch (\RuntimeException $e) {
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'email' => $email,
                'product' => $product,
                'tier' => $tier,
            ]);
            Logger::error($context);

            return Response::json([
                'error_code' => 'creation_failed',
                'message' => 'Failed to create license due to server error',
            ], 500);
        }
    }
}
