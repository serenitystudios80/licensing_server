<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;
use App\Support\Clock;

/**
 * HmacAuthenticator - HMAC-SHA256 signature verification for API requests.
 *
 * Implements 3-step ordered validation (Requirement 3 AC7):
 * 1. Field presence/format check (X-Timestamp and X-Signature headers)
 * 2. Timestamp within ±300s window
 * 3. Signature verification using hash_equals() for timing-attack safety
 *
 * Signature is computed over: "{timestamp}.{rawBody}"
 *
 * Per Requirements 3.1-3.7 and design.md Security section.
 */
final class HmacAuthenticator
{
    /**
     * Maximum allowed time skew in seconds (±300s = ±5 minutes).
     */
    private const MAX_TIME_SKEW = 300;

    private Clock $clock;

    public function __construct(Clock $clock)
    {
        $this->clock = $clock;
    }

    /**
     * Verify HMAC signature on a request.
     *
     * Returns a Result indicating success or failure with specific error codes.
     *
     * Fixed 3-step order (never logs or returns the secret):
     * 1. Field presence/format
     * 2. Timestamp validation (±300s)
     * 3. Signature verification (constant-time comparison)
     *
     * @param Request $request The incoming request
     * @param string $secret The shared HMAC secret
     * @return HmacResult Success or failure with error details
     */
    public function verify(Request $request, string $secret): HmacResult
    {
        // Step 1: Field presence and format validation
        $timestamp = $request->getHeader('X-Timestamp');
        $signature = $request->getHeader('X-Signature');

        if ($timestamp === null || $signature === null) {
            return HmacResult::failure(
                'missing_hmac_fields',
                'Missing required HMAC headers: X-Timestamp and X-Signature'
            );
        }

        // Validate timestamp format (must be numeric)
        if (!ctype_digit($timestamp)) {
            return HmacResult::failure(
                'invalid_timestamp_format',
                'X-Timestamp must be a Unix timestamp (numeric)'
            );
        }

        $timestampInt = (int) $timestamp;

        // Step 2: Timestamp validation (within ±300s window)
        $now = $this->clock->now();
        $timeDiff = abs($now - $timestampInt);

        if ($timeDiff > self::MAX_TIME_SKEW) {
            return HmacResult::failure(
                'timestamp_out_of_range',
                'Request timestamp is outside the allowed ±300s window'
            );
        }

        // Step 3: Signature verification
        $expectedSignature = $this->computeSignature($timestamp, $request->rawBody, $secret);

        // Use hash_equals() for constant-time comparison (timing-attack prevention)
        if (!hash_equals($expectedSignature, $signature)) {
            return HmacResult::failure(
                'invalid_signature',
                'HMAC signature verification failed'
            );
        }

        // All checks passed
        return HmacResult::success();
    }

    /**
     * Compute HMAC signature for a request.
     *
     * Signature is computed over: "{timestamp}.{rawBody}"
     * using HMAC-SHA256 with the shared secret.
     *
     * This is used both for verification (server-side) and for generating
     * signatures (client-side, when testing).
     *
     * @param string $timestamp Unix timestamp as string
     * @param string $rawBody Raw request body
     * @param string $secret Shared HMAC secret
     * @return string Hex-encoded HMAC signature (64 characters)
     */
    public function computeSignature(string $timestamp, string $rawBody, string $secret): string
    {
        $message = "{$timestamp}.{$rawBody}";
        return hash_hmac('sha256', $message, $secret);
    }
}

/**
 * HmacResult - Result of HMAC verification.
 */
final readonly class HmacResult
{
    private function __construct(
        public bool $success,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {
    }

    public static function success(): self
    {
        return new self(true, null, null);
    }

    public static function failure(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
