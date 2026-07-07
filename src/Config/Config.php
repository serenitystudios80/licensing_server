<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Immutable application configuration.
 *
 * Configuration values are sourced from a `.env` file (parsed by a tiny
 * hand-rolled parser, no third-party dependency) merged with real process
 * environment variables.
 *
 * Precedence: real environment variables (getenv() / $_ENV / $_SERVER)
 * take precedence over values loaded from the `.env` file. This mirrors
 * standard deployment practice: a `.env` file provides local-development
 * defaults, while a real hosting environment (systemd unit, PHP-FPM pool
 * config, container env, CI secrets, etc.) can override any individual
 * key without having to edit a checked-out file. If a key is absent from
 * the real environment, the `.env`-file value (if any) is used instead.
 */
final class Config
{
    /**
     * Every configuration key required by Requirement 21 AC1. All of
     * these must be present and non-empty after merging `.env` with the
     * real environment, or `Config::load()` throws `ConfigException`.
     *
     * @var list<string>
     */
    private const REQUIRED_KEYS = [
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'RAZORPAY_KEY_ID',
        'RAZORPAY_WEBHOOK_SECRET',
        'HMAC_SHARED_SECRET',
        'TRUSTED_PROXY_RANGES',
        'RATE_LIMIT_IP_MAX',
        'RATE_LIMIT_IP_WINDOW_SECONDS',
        'RATE_LIMIT_KEY_MAX',
        'RATE_LIMIT_KEY_WINDOW_SECONDS',
        'SESSION_SECRET',
    ];

    /**
     * @param array<string, string> $values Fully merged, validated configuration values.
     */
    private function __construct(
        private readonly array $values,
    ) {
    }

    /**
     * Loads configuration from the given `.env` file path (defaulting to
     * `<project root>/.env`) merged with the real process environment,
     * validates every required key is present and non-empty, and returns
     * an immutable `Config` instance.
     *
     * @throws ConfigException if a required key is missing or empty.
     */
    public static function load(?string $envFilePath = null): self
    {
        $envFilePath ??= dirname(__DIR__, 2) . '/.env';

        $fileValues = self::parseEnvFile($envFilePath);
        $merged = self::mergeWithRealEnvironment($fileValues);

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $merged) || $merged[$key] === '') {
                throw new ConfigException($key);
            }
        }

        return new self($merged);
    }

    /**
     * Returns the value for a configuration key.
     *
     * @throws ConfigException if the key was not loaded (should not
     *         happen for any key in REQUIRED_KEYS after a successful load).
     */
    public function get(string $key): string
    {
        if (!array_key_exists($key, $this->values) || $this->values[$key] === '') {
            throw new ConfigException($key);
        }

        return $this->values[$key];
    }

    /**
     * Parses a `.env` file into a flat key => value array.
     *
     * Supported syntax (intentionally minimal — this is a tiny parser,
     * not a full dotenv implementation):
     *   - blank lines are skipped
     *   - lines starting with `#` (after trimming) are comments and skipped
     *   - an optional leading `export ` keyword is stripped
     *   - `KEY=value` — value is trimmed of surrounding whitespace
     *   - `KEY="value"` / `KEY='value'` — matching surrounding quotes are stripped
     *   - a missing file yields an empty array (real env vars may still
     *     satisfy required keys)
     *
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $result = [];
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, strlen('export ')));
            }

            $equalsPosition = strpos($line, '=');
            if ($equalsPosition === false) {
                continue;
            }

            $key = trim(substr($line, 0, $equalsPosition));
            $value = trim(substr($line, $equalsPosition + 1));

            if ($key === '') {
                continue;
            }

            $value = self::stripSurroundingQuotes($value);

            $result[$key] = $value;
        }

        return $result;
    }

    private static function stripSurroundingQuotes(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === '\'' && $last === '\'')) {
                return substr($value, 1, $length - 2);
            }
        }

        return $value;
    }

    /**
     * Merges `.env`-file-derived values with the real process environment.
     * Real environment variables win when present (see class docblock for
     * the rationale behind this precedence choice).
     *
     * @param array<string, string> $fileValues
     * @return array<string, string>
     */
    private static function mergeWithRealEnvironment(array $fileValues): array
    {
        $merged = $fileValues;

        foreach (self::REQUIRED_KEYS as $key) {
            $realValue = self::readRealEnv($key);
            if ($realValue !== null) {
                $merged[$key] = $realValue;
            }
        }

        return $merged;
    }

    private static function readRealEnv(string $key): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        if (isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            return (string) $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }

        return null;
    }
}
