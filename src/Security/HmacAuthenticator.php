<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;
use App\Support\Clock;

/**
 * HmacAuthenticator - HMAC-SHA256 request signature verification.
 *
 * Implements stage 5 of the API pipeline (after rate limiting, before business logic).
 *
 * CRITICAL: Verification follows a FIXED 3-step order (Requirement 3 AC7):
 * 1. Field presence/format validation (timestamp and signature headers exist, timestamp is integer)
 * 2. Timestamp expiry check (within ±300s of current time)
 * 3. Signature verification (constant-time comparison of computed vs. provided signature)
 *
 * Short-circuit evaluation: stops at first failure, does NOT proceed to subsequent checks.
 *
 * Signature is computed over: "{timestamp}.{rawBody}" using HMAC-SHA256 with shared secret.
 *
 * SECURITY RULES:
 * - Never logs the shared secret
 * - Never returns the secret in any result
 * - Uses hash_equals() for constant-time comparison (prevents timing attacks)
 * - 300-second window prevents replay attacks (Requirement 3 AC3)
 *
 * Per Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7 and design.md HmacAuthenticator section.
 */
final class HmacAuthenticator
{
    /**
     * Timestamp window: ±300 seconds (5 minutes).
     * Per Requirement 3 AC3.
     */
    private const TIMESTAMP_WINDOW = 300;

    /**
     * Header name for timestamp (provisional - see design.md).
     */
    private const TIMESTAMP_HEADER = 'X-Serb-Timestamp';

    /**
     * Header name for signature (provisional - see design.md).
     */
    private const SIGNATURE_HEADER = 'X-Serb-Signature';

    public function __construct(
        private Clock $clock,
    ) {
    }

    /**
     * Verify HMAC authentication for a request.
     *
     * Performs the 3-step verification in fixed order (short-circuit on first failure):
     * 1. Field presence/format
     * 2. Timestamp expiry (±300s)
     * 3. Signature verification (constant-time)
     *
     * @param Request $request The incoming request
     * @param string $secret HMAC shared secret (never logged or returned)
     * @return HmacResult Verification result (ok, malformed, expired, or invalid_signature)
     */
    public function verify(Request $request, string $secret): HmacResult
    {
        // Step 1: Field presence/format validation
        $timestampHeader = $request->getHeader(self::TIMESTAMP_HEADER);
        $signatureHeader = $request->getHeader(self::SIGNATURE_HEADER);

        if ($timestampHeader === null) {
            return HmacResult::malformed(
                'Missing required header: ' . self::TIMESTAMP_HEADER . '. ' .
                'HMAC authentication requires timestamp and signature headers.'
            );
        }

        if ($signatureHeader === null) {
            return HmacResult::malformed(
                'Missing required header: ' . self::SIGNATURE_HEADER . '. ' .
                'HMAC authentication requires timestamp and signature headers.'
            );
        }

        // Timestamp must be a valid integer
        if (!ctype_digit($timestampHeader)) {
            return HmacResult::malformed(
                'Invalid timestamp format in ' . self::TIMESTAMP_HEADER . ': must be a Unix epoch integer (seconds). ' .
                "Received: {$timestampHeader}"
            );
        }

        $timestamp = (int) $timestampHeader;
        $providedSignature = $signatureHeader;

        // Step 2: Timestamp expiry check (±300s)
        $now = $this->clock->now();
        $timeDiff = abs($now - $timestamp);

        if ($timeDiff > self::TIMESTAMP_WINDOW) {
            $direction = $timestamp < $now ? 'past' : 'future';
            return HmacResult::expired(
                "Request timestamp expired: {$timeDiff} seconds difference from server time " .
                "(max allowed: " . self::TIMESTAMP_WINDOW . " seconds). " .
                "Request timestamp is too far in the {$direction}. " .
                "Check client system clock synchronization."
            );
        }

        // Step 3: Signature verification (constant-time)
        $expectedSignature = $this->computeSignature($timestamp, $request->rawBody, $secret);

        if (!hash_equals($expectedSignature, strtolower($providedSignature))) {
            return HmacResult::invalidSignature(
                'HMAC signature verification failed. The provided signature does not match the expected value. ' .
                'Check that the shared secret matches on both client and server, and that the signature is ' .
                'computed over "{timestamp}.{rawBody}" using HMAC-SHA256.'
            );
        }

        // All checks passed
        return HmacResult::ok();
    }

    /**
     * Compute HMAC-SHA256 signature for a timestamp and body.
     *
     * Signature is computed over: "{timestamp}.{rawBody}"
     * (timestamp as integer string, literal dot, raw request body).
     *
     * Returns lowercase hexadecimal string.
     *
     * This method is public for client-side signature generation (not just verification).
     *
     * @param int $timestamp Unix epoch timestamp (seconds)
     * @param string $body Raw request body
     * @param string $secret HMAC shared secret
     * @return string Lowercase hex signature
     */
    public function computeSignature(int $timestamp, string $body, string $secret): string
    {
        $message = "{$timestamp}.{$body}";
        return hash_hmac('sha256', $message, $secret);
    }

    /**
     * Get the timestamp window in seconds (for testing/documentation).
     *
     * @return int Timestamp window (300 seconds = 5 minutes)
     */
    public static function getTimestampWindow(): int
    {
        return self::TIMESTAMP_WINDOW;
    }

    /**
     * Get the timestamp header name (for client integration).
     *
     * @return string Header name (provisional)
     */
    public static function getTimestampHeader(): string
    {
        return self::TIMESTAMP_HEADER;
    }

    /**
     * Get the signature header name (for client integration).
     *
     * @return string Header name (provisional)
     */
    public static function getSignatureHeader(): string
    {
        return self::SIGNATURE_HEADER;
    }
}

/**
 * HmacResult - HMAC verification result.
 *
 * Returned by HmacAuthenticator::verify() to indicate verification outcome.
 */
final readonly class HmacResult
{
    private function __construct(
        public HmacStatus $status,
        public ?string $errorMessage,
    ) {
    }

    public static function ok(): self
    {
        return new self(HmacStatus::OK, null);
    }

    public static function malformed(string $message): self
    {
        return new self(HmacStatus::MALFORMED, $message);
    }

    public static function expired(string $message): self
    {
        return new self(HmacStatus::EXPIRED, $message);
    }

    public static function invalidSignature(string $message): self
    {
        return new self(HmacStatus::INVALID_SIGNATURE, $message);
    }

    public function isOk(): bool
    {
        return $this->status === HmacStatus::OK;
    }
}

/**
 * HmacStatus - HMAC verification status enum.
 */
enum HmacStatus
{
    case OK;
    case MALFORMED;
    case EXPIRED;
    case INVALID_SIGNATURE;
}
