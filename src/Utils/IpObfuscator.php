<?php

namespace AdminIntelligence\LogShipper\Utils;

class IpObfuscator
{
    /**
     * Obfuscate an IP address using the specified method.
     *
     * @param  string|null  $ip  The IP address to obfuscate
     * @param  string  $method  The obfuscation method ('mask' or 'hash')
     * @return string|null The obfuscated IP address or null if input is null
     */
    public static function obfuscate(?string $ip, string $method = 'mask'): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }

        return match ($method) {
            'mask' => self::maskIp($ip),
            'hash' => self::hashIp($ip),
            default => $ip,
        };
    }

    /**
     * Mask the IP address by zeroing out the last octets (IPv4) or segments (IPv6).
     *
     * IPv4: 192.168.1.100 → 192.168.1.0
     * IPv6: 2001:db8:85a3::8a2e:370:7334 → 2001:db8:85a3::
     *
     * @param  string  $ip  The IP address to mask
     * @return string The masked IP address
     */
    private static function maskIp(string $ip): string
    {
        // Detect if it's IPv6
        if (str_contains($ip, ':')) {
            return self::maskIpv6($ip);
        }

        return self::maskIpv4($ip);
    }

    /**
     * Mask an IPv4 address by zeroing out the last octet.
     *
     * @param  string  $ip  The IPv4 address
     * @return string The masked IPv4 address
     */
    private static function maskIpv4(string $ip): string
    {
        // Validate IPv4 format
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }

        $parts = explode('.', $ip);
        $parts[3] = '0';

        return implode('.', $parts);
    }

    /**
     * Mask an IPv6 address by preserving the /64 prefix.
     *
     * Standard IPv6 subnetting uses /64 prefix (first 4 segments).
     *
     * @param  string  $ip  The IPv6 address
     * @return string The masked IPv6 address
     */
    private static function maskIpv6(string $ip): string
    {
        // Validate IPv6 format
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }

        // Expand IPv6 to full format to handle '::' consistently
        $hex = unpack('H*hex', inet_pton($ip));
        $ipFull = substr(preg_replace('/([a-f0-9]{4})/', '$1:', $hex['hex']), 0, -1);

        $parts = explode(':', $ipFull);

        // Keep the first 4 segments (64 bits) and zero the rest
        // Standard subnet prefix for IPv6 is usually /64
        for ($i = 4; $i < 8; $i++) {
            $parts[$i] = '0000';
        }

        // Re-compress the address
        return inet_ntop(inet_pton(implode(':', $parts)));
    }

    /**
     * Hash an IP address using a one-way hash function.
     *
     * This maintains consistency (same IP always produces same hash) while
     * preventing reverse identification.
     *
     * @param  string  $ip  The IP address to hash
     * @return string The hashed IP address (hex format with 'ip_' prefix)
     */
    private static function hashIp(string $ip): string
    {
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        // Use SHA256 for hashing with a salt from app key for consistency
        $salt = config('app.key', 'laravel');
        $hash = hash('sha256', $salt . $ip);

        // Return shortened hash with prefix for clarity
        return 'ip_' . substr($hash, 0, 16);
    }
}
