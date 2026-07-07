<?php

declare(strict_types=1);

namespace App\Security;

/**
 * ClientIpResolver - Resolve true client IP from requests behind proxies.
 *
 * Implements the Requirement 8 decision tree for IP resolution:
 * - If REMOTE_ADDR is in trusted proxy ranges AND X-Forwarded-For is present:
 *   Use rightmost valid IP from X-Forwarded-For
 * - Otherwise: Use REMOTE_ADDR
 *
 * Validates IP format and falls back to REMOTE_ADDR on invalid header values.
 *
 * Per Requirements 8.1-8.7 and design.md Security section.
 */
final class ClientIpResolver
{
    /**
     * Resolve the client IP address.
     *
     * Decision tree:
     * 1. Check if REMOTE_ADDR is a trusted proxy
     * 2. If yes and X-Forwarded-For exists: parse rightmost valid IP
     * 3. If no or invalid header: use REMOTE_ADDR
     *
     * @param array<string, mixed> $serverVars $_SERVER superglobal
     * @param TrustedProxyRanges $trustedRanges Configured trusted proxy ranges
     * @return string Resolved IP address (always valid IPv4 or IPv6)
     */
    public function resolve(array $serverVars, TrustedProxyRanges $trustedRanges): string
    {
        $remoteAddr = $serverVars['REMOTE_ADDR'] ?? '';

        // Validate REMOTE_ADDR format
        if (!$this->isValidIp($remoteAddr)) {
            return '0.0.0.0'; // Fallback for invalid/missing REMOTE_ADDR
        }

        // Check if REMOTE_ADDR is a trusted proxy
        if (!$trustedRanges->contains($remoteAddr)) {
            // Not behind a trusted proxy, use REMOTE_ADDR directly
            return $remoteAddr;
        }

        // Behind trusted proxy: check X-Forwarded-For header
        $forwardedFor = $serverVars['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($forwardedFor === '') {
            // No X-Forwarded-For header, use REMOTE_ADDR
            return $remoteAddr;
        }

        // Parse X-Forwarded-For: take rightmost valid IP
        $ips = array_map('trim', explode(',', $forwardedFor));
        $ips = array_reverse($ips); // Rightmost first

        foreach ($ips as $ip) {
            if ($this->isValidIp($ip)) {
                return $ip;
            }
        }

        // All IPs in X-Forwarded-For were invalid, fall back to REMOTE_ADDR
        return $remoteAddr;
    }

    /**
     * Validate IP address format (IPv4 or IPv6).
     *
     * @param string $ip IP address to validate
     * @return bool True if valid IPv4 or IPv6
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
