<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared secret-redaction rule used by both `Logger` and `ErrorContext`.
 *
 * This exists as a single, shared static helper specifically so the
 * pattern list lives in exactly one place: `Logger` writes log lines,
 * `ErrorContext` builds diagnostic strings for those log lines, and both
 * must apply the identical redaction rule so a secret can never slip
 * through one path but not the other.
 *
 * Redaction is a structural safety net, not a caller convention: any
 * context array key whose name matches a known secret-indicating
 * pattern (case-insensitively) has its value replaced with a fixed
 * marker, regardless of what a caller passes in. This covers, among
 * others, the HMAC shared secret, Razorpay API key/webhook secret, DB
 * password, session secret, and password hashes (Requirement 21 AC4,
 * Requirement 22 AC2, and the design's "Error message and logging
 * specificity policy").
 */
final class SecretRedactor
{
    public const REDACTED = '[REDACTED]';

    /**
     * Case-insensitive substring patterns matched against array key
     * names. Several entries are already covered by a broader pattern
     * (e.g. `webhook_secret` and `session_secret` are already caught by
     * `secret`; `password_hash` is already caught by `password`) but are
     * listed explicitly anyway so the rule stays self-documenting and
     * resilient to the broader pattern ever being narrowed later.
     *
     * @var list<string>
     */
    private const KEY_PATTERNS = [
        'secret',
        'password',
        'pass',
        'hmac',
        'token',
        'api_key',
        'apikey',
        'webhook_secret',
        'session_secret',
        'password_hash',
    ];

    private function __construct()
    {
        // Static-only helper; never instantiated.
    }

    /**
     * Recursively redacts values in a context array whose key name
     * matches a known secret-indicating pattern. Non-matching keys keep
     * their original value; nested arrays are redacted recursively so a
     * secret buried in a sub-array (e.g. a decoded request payload)
     * cannot escape redaction either.
     *
     * @param array<int|string, mixed> $context
     * @return array<int|string, mixed>
     */
    public static function redactContext(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = self::redactContext($value);
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    /**
     * Defensive, best-effort redaction of free-text messages.
     *
     * Context arrays are the primary redaction surface (callers should
     * pass structured data, not embed secrets in message strings), but
     * this catches the common accidental case of a caller interpolating
     * a `key=value` or `key: value` pair directly into a message string.
     * It is not a substitute for passing secrets via `$context` and
     * relying on `redactContext()` — free text can't be redacted with
     * the same reliability as a known key name.
     */
    public static function redactMessage(string $message): string
    {
        $pattern = '/(' . implode('|', self::KEY_PATTERNS) . ')(["\']?\s*[:=]\s*["\']?)([^\s,;"\']+)/i';

        $result = preg_replace($pattern, '$1$2' . self::REDACTED, $message);

        return $result ?? $message;
    }

    private static function isSecretKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::KEY_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
