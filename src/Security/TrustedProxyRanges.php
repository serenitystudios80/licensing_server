<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Logger;

/**
 * TrustedProxyRanges - CIDR range parser for trusted proxy configuration.
 *
 * Parses a comma-separated list of CIDR ranges from config (Cloudflare's published ranges)
 * to determine which proxy headers (CF-Connecting-IP, X-Forwarded-For) can be trusted.
 *
 * Empty/missing/unparseable input yields an empty range set (fail-closed: trust only REMOTE_ADDR).
 *
 * Per Requirements 8.4, 8.6 and design.md ClientIpResolver section.
 */
final class TrustedProxyRanges
{
    /**
     * @param array<array{network: string, mask: string, bits: int}> $ranges
     */
    private function __construct(
        private array $ranges,
    ) {
    }

    /**
     * Parse trusted proxy ranges from config string.
     *
     * Input format: comma-separated CIDR notation (e.g., "173.245.48.0/20,103.21.244.0/22")
     * Supports both IPv4 and IPv6 CIDR notation.
     *
     * Empty/unparseable input yields an empty range set (fail-closed behavior per Requirement 8.6).
     *
     * @param string $csv Comma-separated CIDR ranges
     * @return self
     */
    public static function fromConfig(string $csv): self
    {
        $csv = trim($csv);
        
        if ($csv === '') {
            // Empty config → empty range set (fail-closed)
            return new self([]);
        }

        $ranges = [];
        $parts = array_map('trim', explode(',', $csv));

        foreach ($parts as $cidr) {
            if ($cidr === '') {
                continue; // Skip empty entries
            }

            $parsed = self::parseCidr($cidr);
            if ($parsed !== null) {
                $ranges[] = $parsed;
            } else {
                // Unparseable CIDR → log warning and skip (continue parsing rest)
                Logger::warning("Unparseable CIDR range in TRUSTED_PROXY_RANGES: {$cidr}. Skipping.");
            }
        }

        return new self($ranges);
    }

    /**
     * Check if an IP address is within any of the trusted ranges.
     *
     * @param string $ip IP address to check (IPv4 or IPv6)
     * @return bool True if IP is within a trusted range
     */
    public function contains(string $ip): bool
    {
        $ipLong = @inet_pton($ip);
        if ($ipLong === false) {
            // Invalid IP format → not trusted
            return false;
        }

        foreach ($this->ranges as $range) {
            $networkLong = @inet_pton($range['network']);
            $maskLong = @inet_pton($range['mask']);

            if ($networkLong === false || $maskLong === false) {
                // Range parse failure (shouldn't happen if parsed correctly in fromConfig)
                continue;
            }

            // Bitwise AND to check if IP is in network
            if (($ipLong & $maskLong) === ($networkLong & $maskLong)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the range set is empty (fail-closed mode).
     *
     * @return bool True if no trusted ranges configured
     */
    public function isEmpty(): bool
    {
        return empty($this->ranges);
    }

    /**
     * Parse a CIDR notation string into network, mask, and bits.
     *
     * @param string $cidr CIDR notation (e.g., "173.245.48.0/20")
     * @return array{network: string, mask: string, bits: int}|null Parsed range or null if invalid
     */
    private static function parseCidr(string $cidr): ?array
    {
        if (!str_contains($cidr, '/')) {
            // Missing slash → invalid CIDR
            return null;
        }

        [$network, $bitsStr] = explode('/', $cidr, 2);
        $network = trim($network);
        $bitsStr = trim($bitsStr);

        if (!ctype_digit($bitsStr)) {
            // Non-numeric bits → invalid
            return null;
        }

        $bits = (int) $bitsStr;

        // Validate IP format
        $networkBinary = @inet_pton($network);
        if ($networkBinary === false) {
            // Invalid IP format
            return null;
        }

        $isIPv6 = str_contains($network, ':');
        $maxBits = $isIPv6 ? 128 : 32;

        if ($bits < 0 || $bits > $maxBits) {
            // Bits out of range
            return null;
        }

        // Compute netmask
        if ($isIPv6) {
            // IPv6: 128-bit mask
            $mask = str_repeat('f', $bits / 4);
            if ($bits % 4 !== 0) {
                $mask .= dechex(0xf << (4 - ($bits % 4)) & 0xf);
            }
            $mask = str_pad($mask, 32, '0');
            $mask = pack('H*', $mask);
        } else {
            // IPv4: 32-bit mask
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
            $mask = pack('N', $mask);
        }

        $maskStr = inet_ntop($mask);
        if ($maskStr === false) {
            // Mask conversion failed
            return null;
        }

        return [
            'network' => $network,
            'mask' => $maskStr,
            'bits' => $bits,
        ];
    }

    /**
     * Get the count of trusted ranges (for testing/debugging).
     *
     * @return int Number of trusted ranges
     */
    public function count(): int
    {
        return count($this->ranges);
    }
}
