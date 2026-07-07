<?php

declare(strict_types=1);

namespace App\Api;

/**
 * FieldNames - API request field name constants.
 *
 * Centralized constants for all API endpoint field names to avoid typos and
 * ensure consistency across handlers.
 *
 * Per design.md API handlers section.
 */
final class FieldNames
{
    // Common fields
    public const LICENSE_KEY = 'license_key';
    public const SITE_URL = 'site_url';

    // Activate endpoint
    public const ACTIVATE_LICENSE_KEY = self::LICENSE_KEY;
    public const ACTIVATE_SITE_URL = self::SITE_URL;

    // Validate endpoint
    public const VALIDATE_LICENSE_KEY = self::LICENSE_KEY;
    public const VALIDATE_SITE_HASH = 'site_hash';

    // Deactivate endpoint
    public const DEACTIVATE_LICENSE_KEY = self::LICENSE_KEY;
    public const DEACTIVATE_SITE_HASH = 'site_hash';

    // Response fields
    public const RESPONSE_SUCCESS = 'success';
    public const RESPONSE_STATUS = 'status';
    public const RESPONSE_EXPIRES_AT = 'expires_at';
    public const RESPONSE_ACTIVATION_ID = 'activation_id';
    public const RESPONSE_SLOTS_AVAILABLE = 'slots_available';
}
