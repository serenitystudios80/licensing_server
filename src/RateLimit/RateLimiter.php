<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Config\Config;
use App\Support\Logger;

/**
 * RateLimiter - Sliding-window rate limit enforcement.
 *
 * Evaluates per-IP and per-license-key rate limits independently using a
 * MariaDB-backed sliding window (no Redis/APCu required).
 *
 * KEY BEHAVIORS (fail-open resilience per Requirements 2.3, 9.5, 9.8):
 * - If one scope's store read fails → treat that scope as "not exceeded" (fail-open)
 * - If the other scope's read succeeds and shows exceeded → still reject the request
 * - Both scopes failing → request allowed (fail-open)
 * - Write failures (record()) never abort requests (handled by RateLimitRepository)
 *
 * INDEPENDENT SCOPE EVALUATION (Requirement 9 AC5):
 * - Per-IP and per-license-key checks are evaluated separately
 * - A request is rejected if EITHER limit is exceeded (when successfully evaluated)
 * - A read failure on one scope does NOT prevent the other scope from being enforced
 *
 * Per Requirements 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.8 and design.md RateLimiter section.
 */
final class RateLimiter
{
    private RateLimitRepository $repo;
    private int $ipMax;
    private int $ipWindowSeconds;
    private int $keyMax;
    private int $keyWindowSeconds;

    public function __construct(
        Config $config,
        RateLimitRepository $repo,
    ) {
        $this->repo = $repo;
        
        // Load rate limit thresholds from config (Requirement 9 AC6)
        $this->ipMax = (int) $config->get('RATE_LIMIT_IP_MAX');
        $this->ipWindowSeconds = (int) $config->get('RATE_LIMIT_IP_WINDOW_SECONDS');
        $this->keyMax = (int) $config->get('RATE_LIMIT_KEY_MAX');
        $this->keyWindowSeconds = (int) $config->get('RATE_LIMIT_KEY_WINDOW_SECONDS');

        // Validate thresholds are positive integers (Requirement 9 AC6)
        if ($this->ipMax <= 0 || $this->ipWindowSeconds <= 0 || 
            $this->keyMax <= 0 || $this->keyWindowSeconds <= 0) {
            throw new \InvalidArgumentException(
                'Rate limit thresholds must be positive integers. ' .
                "Received: IP {$this->ipMax}/{$this->ipWindowSeconds}s, " .
                "Key {$this->keyMax}/{$this->keyWindowSeconds}s"
            );
        }
    }

    /**
     * Check if a request should be rate limited.
     *
     * Evaluates per-IP and per-license-key limits independently.
     * Request is rejected if EITHER limit is exceeded (when successfully evaluated).
     *
     * Fail-open behavior: Store read failure on one scope is treated as "not exceeded"
     * for that scope, but the other scope's result (if successful) is still enforced.
     *
     * @param string $ip Client IP address (resolved by ClientIpResolver)
     * @param string|null $licenseKey License key (null for /activate before validation)
     * @param int $now Current Unix epoch timestamp (seconds)
     * @param string $endpoint Endpoint name: 'activate', 'validate', 'deactivate'
     * @return RateLimitDecision Decision with exceeded status and reason
     */
    public function check(string $ip, ?string $licenseKey, int $now, string $endpoint): RateLimitDecision
    {
        // Evaluate per-IP limit
        $ipResult = $this->checkScope('ip', $ip, $this->ipMax, $this->ipWindowSeconds, $now);

        // Evaluate per-license-key limit (if key provided)
        $keyResult = null;
        if ($licenseKey !== null) {
            $keyResult = $this->checkScope('license_key', $licenseKey, $this->keyMax, $this->keyWindowSeconds, $now);
        }

        // Determine final decision: reject if EITHER scope exceeded
        $ipExceeded = $ipResult['exceeded'];
        $keyExceeded = $keyResult !== null && $keyResult['exceeded'];

        if ($ipExceeded || $keyExceeded) {
            // At least one limit exceeded → reject
            $reasons = [];
            if ($ipExceeded) {
                $reasons[] = "Per-IP limit exceeded: {$ipResult['count']} requests from IP {$ip} " .
                             "in last {$this->ipWindowSeconds}s (max: {$this->ipMax})";
            }
            if ($keyExceeded) {
                $reasons[] = "Per-license-key limit exceeded: {$keyResult['count']} requests for key {$licenseKey} " .
                             "in last {$this->keyWindowSeconds}s (max: {$this->keyMax})";
            }

            return RateLimitDecision::exceeded(implode('. ', $reasons));
        }

        // Neither limit exceeded (or both failed open) → allow request
        // Record this attempt for future rate limit checks
        $this->repo->record('ip', $ip, $endpoint, $now);
        if ($licenseKey !== null) {
            $this->repo->record('license_key', $licenseKey, $endpoint, $now);
        }

        return RateLimitDecision::allowed();
    }

    /**
     * Check a single rate limit scope (per-IP or per-license-key).
     *
     * Returns an array with:
     * - exceeded: bool (true if limit exceeded, false if not exceeded or failed open)
     * - count: int (request count, or 0 if failed to read)
     *
     * Catches RateLimitStoreException and treats it as "not exceeded" (fail-open)
     * per Requirement 9 AC8, while logging the failure.
     *
     * @param string $scope 'ip' or 'license_key'
     * @param string $scopeValue Scope value (IP address or license key)
     * @param int $max Maximum allowed requests
     * @param int $windowSeconds Sliding window duration (seconds)
     * @param int $now Current Unix epoch timestamp (seconds)
     * @return array{exceeded: bool, count: int}
     */
    private function checkScope(string $scope, string $scopeValue, int $max, int $windowSeconds, int $now): array
    {
        try {
            // Calculate window start: now - windowSeconds
            $windowStart = $now - $windowSeconds;
            
            // Count requests in sliding window
            $count = $this->repo->countSince($scope, $scopeValue, $windowStart);
            
            // Check if limit exceeded
            $exceeded = ($count >= $max);
            
            return ['exceeded' => $exceeded, 'count' => $count];

        } catch (RateLimitStoreException $e) {
            // Store read failed → fail open for this scope (Requirement 9 AC8)
            // Log the failure but treat as "not exceeded" so request can proceed
            Logger::warning(
                "Rate limit store read failed for scope '{$scope}' value '{$scopeValue}': {$e->getMessage()}. " .
                "Failing open for this scope (allowing request to proceed)."
            );
            
            return ['exceeded' => false, 'count' => 0];
        }
    }

    /**
     * Get the maximum sliding window duration (for cleanup boundary).
     *
     * Returns the larger of IP window and key window durations.
     * Used by Sweep_Job cleanup (Requirement 2 AC4).
     *
     * @return int Maximum window duration in seconds
     */
    public function getMaxWindowSeconds(): int
    {
        return max($this->ipWindowSeconds, $this->keyWindowSeconds);
    }

    /**
     * Get rate limit configuration (for testing/debugging).
     *
     * @return array{ipMax: int, ipWindowSeconds: int, keyMax: int, keyWindowSeconds: int}
     */
    public function getConfig(): array
    {
        return [
            'ipMax' => $this->ipMax,
            'ipWindowSeconds' => $this->ipWindowSeconds,
            'keyMax' => $this->keyMax,
            'keyWindowSeconds' => $this->keyWindowSeconds,
        ];
    }
}

/**
 * RateLimitDecision - Rate limit check result.
 *
 * Returned by RateLimiter::check() to indicate whether request should be allowed or rejected.
 */
final readonly class RateLimitDecision
{
    private function __construct(
        public bool $exceeded,
        public ?string $reason,
    ) {
    }

    public static function allowed(): self
    {
        return new self(exceeded: false, reason: null);
    }

    public static function exceeded(string $reason): self
    {
        return new self(exceeded: true, reason: $reason);
    }

    public function isAllowed(): bool
    {
        return !$this->exceeded;
    }
}
