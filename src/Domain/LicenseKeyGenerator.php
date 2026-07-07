<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * License key generator.
 *
 * Produces cryptographically secure license keys matching the format:
 *     SERB-XXXXX-XXXXX-XXXXX-XXXXX
 *
 * where X is an uppercase alphanumeric character (A-Z, 0-9).
 *
 * Per Requirements 1.1 and 4.1 design task, the key format is a
 * product-specific prefix (`SERB` for Serenity Booking) followed by four
 * 5-character blocks. Future products may use different prefixes (e.g.,
 * `SERC` for a Cloud tier) without changing this generator logic.
 */
final class LicenseKeyGenerator
{
    /**
     * Character set for key generation: uppercase letters and digits.
     * Excludes ambiguous characters (I, O, 0, 1) to reduce support burden
     * from visual confusion when customers type keys manually.
     */
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Number of characters per block (5).
     */
    private const BLOCK_LENGTH = 5;

    /**
     * Number of blocks in the key (4).
     */
    private const BLOCK_COUNT = 4;

    /**
     * Product-specific prefix for Serenity Booking keys.
     */
    private const PREFIX = 'SERB';

    /**
     * Generate a new license key.
     *
     * Returns a string in the format `SERB-XXXXX-XXXXX-XXXXX-XXXXX`.
     * Uses `random_int()` for cryptographic randomness.
     */
    public function generate(): string
    {
        $blocks = [];
        $charsetLength = strlen(self::CHARSET);

        for ($i = 0; $i < self::BLOCK_COUNT; $i++) {
            $block = '';
            for ($j = 0; $j < self::BLOCK_LENGTH; $j++) {
                $block .= self::CHARSET[random_int(0, $charsetLength - 1)];
            }
            $blocks[] = $block;
        }

        return self::PREFIX . '-' . implode('-', $blocks);
    }

    /**
     * Validate that a string matches the expected license key format.
     *
     * Returns true if the key matches `SERB-XXXXX-XXXXX-XXXXX-XXXXX`
     * where each X is an uppercase alphanumeric character from CHARSET.
     *
     * This is a format check only; it does NOT verify the key exists in
     * the database or is assigned to a valid license.
     */
    public function isValidFormat(string $key): bool
    {
        // Pattern: SERB- followed by exactly 4 blocks of 5 characters each,
        // where each character is from our CHARSET.
        $charsetPattern = '[' . preg_quote(self::CHARSET, '/') . ']';
        $blockPattern = $charsetPattern . '{' . self::BLOCK_LENGTH . '}';
        $pattern = '/^' . preg_quote(self::PREFIX, '/') . '-'
            . '(' . $blockPattern . '-){3}' . $blockPattern . '$/';

        return preg_match($pattern, $key) === 1;
    }
}
