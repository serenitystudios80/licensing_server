<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Config\Config;
use App\Support\ErrorContext;
use App\Support\Logger;

/**
 * RateLimiter - Sliding-window rate limiting.
 *
 * Evaluates per-IP and per-license-key limits independently:
 * - If one scope fails to read: fail-open for that scope, still enforce the other
 * - Both scopes must pass for the request to proceed
 *
 * Configuration:
 * - RATE_LIMIT_IP_MAX / RATE_LIMIT_IP_WINDOW_SECONDS
 * - RATE_LIMIT_KEY_MAX / RATE_LIMIT_KEY_WINDOW_SECONDS
 *
 * Per Requirements 9.1-9.6, 9.8 and design.md Rate limiting section.
 */
final class RateLimiter
{
    private RateLimitRepository $repo;
    private int $ipMax;
    private int $ipWindow;
    private int $keyMax;
    private int $keyWindow;

    public function __construct(Config $config, RateLimitRepository $repo)
    {
        $this->repo = $repo;
        $this->ipMax = (int) $config->get('RATE_LIMIT_IP_MAX');
        $this->ipWindow = (int) $config->get('RATE_LIMIT_IP_WINDOW_SECONDS');
        $this->keyMax = (int) $config->get('RATE_LIMIT_KEY_MAX');
        $this->keyWindow = (int) $config->get('RATE_LIMIT_KEY_WINDOW_SECONDS');
    }

    /**
     * Check rate limits for a request.
     *
     * Evaluates per-IP and per-license-key limits independently.
     * - Catches store exceptions: fail-open for that scope
     * - Records the request for successful scopes
     * - Both scopes must pass for request to proceed
     *
     * @param string $ip Client IP address
     * @param string|null $licenseKey License key from request (if present)
     * @param int $now Current Unix timestamp
     * @return RateLimitDecision Decision object (exceeded or allowed)
     */
    public function check(string $ip, ?string $licenseKey, int $now): RateLimitDecision
    {
        $ipExceeded = false;
        $keyExceeded = false;

        // Check per-IP limit
        try {
            $ipSince = $now - $this->ipWindow;
            $ipCount = $this->repo->countSince('ip', $ip, $ipSince);

            if ($ipCount >= $this->ipMax) {
                $ipExceeded = true;
            } else {
                // Record this request for IP scope
                $this->repo->record('ip', $ip, $now);
            }
        } catch (RateLimitStoreException $e) {
            // Fail-open for IP scope: log and continue
            $context = ErrorContext::describe($e, [
                'method' => __METHOD__,
                'scope' => 'ip',
                'identifier' => $ip,
            ]);
            Logger::error($context);

            // IP scope fails open (don't set $ipExceeded)
        }

        // Check per-license-key limit (if license_key present)
        if ($licenseKey !== null) {
            try {
                $keySince = $now - $this->keyWindow;
                $keyCount = $this->repo->countSince('license_key', $licenseKey, $keySince);

                if ($keyCount >= $this->keyMax) {
                    $keyExceeded = true;
                } else {
                    // Record this request for license_key scope
                    $this->repo->record('license_key', $licenseKey, $now);
                }
            } catch (RateLimitStoreException $e) {
                // Fail-open for license_key scope: log and continue
                $context = ErrorContext::describe($e, [
                    'method' => __METHOD__,
                    'scope' => 'license_key',
                    'identifier' => $licenseKey,
                ]);
                Logger::error($context);

                // License_key scope fails open (don't set $keyExceeded)
            }
        }

        // Decision: if either scope exceeded, block the request
        if ($ipExceeded) {
            return RateLimitDecision::exceeded('Per-IP rate limit exceeded');
        }

        if ($keyExceeded) {
            return RateLimitDecision::exceeded('Per-license-key rate limit exceeded');
        }

        return RateLimitDecision::allowed();
    }
}

/**
 * RateLimitDecision - Result of rate limit check.
 */
final readonly class RateLimitDecision
{
    private function __construct(
        public bool $exceeded,
        public ?string $reason,
    ) {
    }

    public static function exceeded(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function allowed(): self
    {
        return new self(false, null);
    }
}
