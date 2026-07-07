<?php

declare(strict_types=1);

namespace App\Security;

/**
 * TrustedProxyRanges - Parse and check trusted proxy IP ranges.
 *
 * Parses TRUSTED_PROXY_RANGES config (comma-separated IP addresses or CIDR ranges).
 * Empty/unparseable input yields an empty range set (no proxies trusted).
 *
 * Per Requirements 8.2, 8.4, 8.5 and design.md Security section.
 */
final class TrustedProxyRanges
{
    /**
     * @var list<string> List of individual IPs or CIDR ranges
     */
    private array $ranges;

    /**
     * @param list<string> $ranges List of IP addresses or CIDR ranges
     */
    private function __construct(array $ranges)
    {
        $this->ranges = $ranges;
    }

    /**
     * Parse trusted proxy ranges from config string.
     *
     * Format: "10.0.0.1,192.168.1.0/24,172.16.0.0/12"
     * Empty or unparseable input → empty range set.
     *
     * @param string $csv Comma-separated IP ranges
     * @return self
     */
    public static function fromConfig(string $csv): self
    {
        if (trim($csv) === '') {
            return new self([]);
        }

        $ranges = array_map('trim', explode(',', $csv));
        $ranges = array_filter($ranges, fn($r) => $r !== '');

        return new self(array_values($ranges));
    }

    /**
     * Check if an IP address is in the trusted ranges.
     *
     * @param string $ip IP address to check
     * @return bool True if IP is in any trusted range
     */
    public function contains(string $ip): bool
    {
        if (empty($this->ranges)) {
            return false; // No trusted proxies configured
        }

        foreach ($this->ranges as $range) {
            if ($this->ipMatchesRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP matches a range (single IP or CIDR).
     *
     * @param string $ip IP to check
     * @param string $range IP or CIDR range (e.g., "192.168.1.0/24")
     * @return bool True if IP is in range
     */
    private function ipMatchesRange(string $ip, string $range): bool
    {
        // Simple single IP comparison
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        // CIDR range comparison
        [$subnet, $mask] = explode('/', $range, 2);
        $mask = (int) $mask;

        // Convert IPs to binary for comparison
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false; // Invalid IP format
        }

        // IPv4 or IPv6 handling
        $bitsToCompare = $mask;
        $bytesToCompare = (int) ceil($bitsToCompare / 8);

        for ($i = 0; $i < $bytesToCompare; $i++) {
            $ipByte = ord($ipBin[$i] ?? "\x00");
            $subnetByte = ord($subnetBin[$i] ?? "\x00");

            $bitsInThisByte = min(8, $bitsToCompare - ($i * 8));
            $maskByte = ~((1 << (8 - $bitsInThisByte)) - 1) & 0xFF;

            if (($ipByte & $maskByte) !== ($subnetByte & $maskByte)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if any trusted proxies are configured.
     *
     * @return bool True if at least one range is configured
     */
    public function isEmpty(): bool
    {
        return empty($this->ranges);
    }
}
