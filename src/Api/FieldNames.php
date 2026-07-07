<?php

declare(strict_types=1);

namespace App\Api;

/**
 * API Field Names - Provisional JSON field name constants.
 *
 * Per design.md: These field names are PROVISIONAL and must be cross-checked
 * against the existing WordPress client scaffold (class-serb-license.php,
 * serenity-license-manager wrapper plugin) before being treated as final/frozen.
 *
 * Isolating all field-name literals in one place means renaming them later
 * (once the real client is checked) is a one-file change rather than scattered
 * find-and-replace.
 *
 * Per design.md Provisional API field names section.
 */
final class FieldNames
{
    // ========================================================================
    // Request Fields (Client → Server)
    // ========================================================================

    /**
     * License key (all endpoints except webhook).
     * Format: SERB-XXXXX-XXXXX-XXXXX-XXXXX
     */
    public const LICENSE_KEY = 'license_key';

    /**
     * Site URL (human-readable, used by /activate).
     * Example: "https://example.com"
     */
    public const SITE_URL = 'site_url';

    /**
     * Site hash (SHA-256, used by /validate and /deactivate).
     * 64-character lowercase hexadecimal digest.
     */
    public const SITE_HASH = 'site_hash';

    // ========================================================================
    // Response Fields (Server → Client)
    // ========================================================================

    /**
     * License status in response.
     * Values: 'active', 'grace', 'expired', 'revoked'
     */
    public const STATUS = 'status';

    /**
     * License expiry timestamp in response.
     * ISO 8601 datetime string or null for lifetime licenses.
     */
    public const EXPIRES_AT = 'expires_at';

    /**
     * Number of available activation slots (returned by /deactivate).
     * Computed as: activation_limit - count(non-deactivated activations)
     */
    public const SLOTS_AVAILABLE = 'slots_available';

    /**
     * Webhook processing result (returned by /webhook/razorpay).
     * Boolean: true on success
     */
    public const OK = 'ok';

    // ========================================================================
    // Error Response Fields (Uniform across all endpoints)
    // ========================================================================

    /**
     * Error code in structured error responses.
     * Examples: 'validation_error', 'unknown_license', 'rate_limited', etc.
     */
    public const ERROR_CODE = 'error_code';

    /**
     * Human-readable error message in structured error responses.
     * Always specific and developer-friendly per error-handling policy.
     */
    public const MESSAGE = 'message';
}
