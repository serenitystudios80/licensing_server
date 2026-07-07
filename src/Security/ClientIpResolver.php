<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Logger;

/**
 * ClientIpResolver - Real client IP resolution behind Cloudflare proxy.
 *
 * Implements the Requirement 8 decision tree for resolving the true client IP
 * from proxy headers, with security-first fail-closed behavior.
 *
 * Decision logic:
 * 1. If REMOTE_ADDR is within trusted proxy ranges (Cloudflare):
 *    a. Prefer CF-Connecting-IP if present and valid
 *    b. Else use rightmost X-Forwarded-For entry if present and valid
 *    c. Else fall back to REMOTE_ADDR
 * 2. If REMOTE_ADDR is NOT within trusted ranges:
 *    - Use REMOTE_ADDR directly (ignore proxy headers to prevent spoofing)
 * 3. If trusted ranges config is empty/unparseable:
 *    - Use REMOTE_ADDR directly (fail-closed per Requirement 8.6)
 *
 * Invalid IP header fallback (Requirement 8.7): If a proxy header value is not
 * syntactically valid, fall back to REMOTE_ADDR rather than rejecting the request.
 *
 * Per Requirements 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7 and design.md ClientIpResolver section.
 */
final class ClientIpResolver
{
    /**
     * Resolve the real client IP from $_SERVER-style array.
     *
     * @param array<string, string> $serverVars $_SERVER or equivalent (REMOTE_ADDR, HTTP_CF_CONNECTING_IP, HTTP_X_FORWARDED_FOR)
     * @param TrustedProxyRanges $ranges Configured trusted proxy ranges
     * @return string Resolved client IP (always valid, falls back to REMOTE_ADDR on any issue)
     */
    public function resolve(array $serverVars, TrustedProxyRanges $ranges): string
    {
        $remoteAddr = $serverVars['REMOTE_ADDR'] ?? '';

        // Validate REMOTE_ADDR (should always be set and valid by PHP/web server)
        if (!$this->isValidIp($remoteAddr)) {
            // This should never happen in normal operation, but handle gracefully
            Logger::error("Invalid or missing REMOTE_ADDR: {$remoteAddr}. Using 0.0.0.0 as fallback.");
            return '0.0.0.0';
        }

        // Fail-closed if trusted ranges empty/unparseable (Requirement 8.6)
        if ($ranges->isEmpty()) {
            return $remoteAddr;
        }

        // If REMOTE_ADDR not in trusted ranges, use it directly (Requirement 8.3)
        if (!$ranges->contains($remoteAddr)) {
            return $remoteAddr;
        }

        // REMOTE_ADDR is trusted proxy → check CF-Connecting-IP first (Requirement 8.1)
        $cfConnectingIp = $serverVars['HTTP_CF_CONNECTING_IP'] ?? null;
        if ($cfConnectingIp !== null && $this->isValidIp($cfConnectingIp)) {
            return $cfConnectingIp;
        }

        // CF-Connecting-IP absent/invalid → check X-Forwarded-For rightmost (Requirement 8.2)
        $xForwardedFor = $serverVars['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($xForwardedFor !== null) {
            $rightmostIp = $this->extractRightmostIp($xForwardedFor);
            if ($rightmostIp !== null && $this->isValidIp($rightmostIp)) {
                return $rightmostIp;
            }
        }

        // Both proxy headers absent/invalid → fall back to REMOTE_ADDR (Requirement 8.7)
        return $remoteAddr;
    }

    /**
     * Validate that a string is a syntactically valid IP address (IPv4 or IPv6).
     *
     * Per Requirement 8.7: invalid IP header → fall back to REMOTE_ADDR.
     *
     * @param string $ip IP address to validate
     * @return bool True if valid IP format
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Extract the rightmost (last) IP from X-Forwarded-For header.
     *
     * X-Forwarded-For format: "client, proxy1, proxy2"
     * Rightmost entry is the last proxy before our server (most trustworthy if from trusted proxy).
     *
     * @param string $xForwardedFor X-Forwarded-For header value
     * @return string|null Rightmost IP, or null if header is empty
     */
    private function extractRightmostIp(string $xForwardedFor): ?string
    {
        $ips = array_map('trim', explode(',', $xForwardedFor));
        $ips = array_filter($ips, fn($ip) => $ip !== '');

        if (empty($ips)) {
            return null;
        }

        // Return rightmost (last) IP
        return end($ips);
    }
}
