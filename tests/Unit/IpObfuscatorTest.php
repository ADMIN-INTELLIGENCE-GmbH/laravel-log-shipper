<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Tests\TestCase;
use AdminIntelligence\LogShipper\Utils\IpObfuscator;

class IpObfuscatorTest extends TestCase
{
    public function test_obfuscate_with_mask_method_ipv4()
    {
        $ip = '192.168.1.100';
        $obfuscated = IpObfuscator::obfuscate($ip, 'mask');

        $this->assertEquals('192.168.1.0', $obfuscated);
    }

    public function test_obfuscate_with_mask_method_different_ipv4()
    {
        $ip = '8.8.8.8';
        $obfuscated = IpObfuscator::obfuscate($ip, 'mask');

        $this->assertEquals('8.8.8.0', $obfuscated);
    }

    public function test_obfuscate_with_mask_method_ipv6()
    {
        $ip = '2001:db8:85a3::8a2e:370:7334';
        $obfuscated = IpObfuscator::obfuscate($ip, 'mask');

        $this->assertEquals('2001:db8:85a3::', $obfuscated);
    }

    public function test_obfuscate_with_mask_method_ipv6_without_double_colon()
    {
        $ip = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $obfuscated = IpObfuscator::obfuscate($ip, 'mask');

        // Should preserve the /64 prefix (first 4 segments) and zero the rest
        $this->assertEquals('2001:db8:85a3::', $obfuscated);
    }

    public function test_obfuscate_with_hash_method_ipv4()
    {
        $ip = '192.168.1.100';
        $obfuscated = IpObfuscator::obfuscate($ip, 'hash');

        // Hash should start with 'ip_'
        $this->assertStringStartsWith('ip_', $obfuscated);
        // Hash should be deterministic
        $obfuscated2 = IpObfuscator::obfuscate($ip, 'hash');
        $this->assertEquals($obfuscated, $obfuscated2);
    }

    public function test_obfuscate_with_hash_method_ipv6()
    {
        $ip = '2001:db8:85a3::8a2e:370:7334';
        $obfuscated = IpObfuscator::obfuscate($ip, 'hash');

        // Hash should start with 'ip_'
        $this->assertStringStartsWith('ip_', $obfuscated);
        // Hash should be deterministic
        $obfuscated2 = IpObfuscator::obfuscate($ip, 'hash');
        $this->assertEquals($obfuscated, $obfuscated2);
    }

    public function test_hash_method_produces_different_hashes_for_different_ips()
    {
        $ip1 = '192.168.1.100';
        $ip2 = '192.168.1.101';

        $hash1 = IpObfuscator::obfuscate($ip1, 'hash');
        $hash2 = IpObfuscator::obfuscate($ip2, 'hash');

        $this->assertNotEquals($hash1, $hash2);
    }

    public function test_obfuscate_with_null_ip()
    {
        $obfuscated = IpObfuscator::obfuscate(null, 'mask');
        $this->assertNull($obfuscated);
    }

    public function test_obfuscate_with_empty_string_ip()
    {
        $obfuscated = IpObfuscator::obfuscate('', 'mask');
        $this->assertEquals('', $obfuscated);
    }

    public function test_obfuscate_with_invalid_ip_returns_unchanged()
    {
        $invalidIp = 'not-an-ip';
        $obfuscated = IpObfuscator::obfuscate($invalidIp, 'mask');

        $this->assertEquals($invalidIp, $obfuscated);
    }

    public function test_obfuscate_with_invalid_method_returns_unchanged()
    {
        $ip = '192.168.1.100';
        $obfuscated = IpObfuscator::obfuscate($ip, 'unknown_method');

        $this->assertEquals($ip, $obfuscated);
    }

    public function test_obfuscate_default_method_is_mask()
    {
        $ip = '192.168.1.100';
        $obfuscated = IpObfuscator::obfuscate($ip);

        $this->assertEquals('192.168.1.0', $obfuscated);
    }

    public function test_hash_produces_16_character_hex_string()
    {
        $ip = '192.168.1.100';
        $obfuscated = IpObfuscator::obfuscate($ip, 'hash');

        // Should be 'ip_' + 16 hex characters = 19 total
        $this->assertEquals(19, strlen($obfuscated));
        // Should match pattern: ip_ followed by hex characters
        $this->assertMatchesRegularExpression('/^ip_[a-f0-9]{16}$/', $obfuscated);
    }

    public function test_mask_preserves_ip_format()
    {
        $ips = [
            '10.0.0.1' => '10.0.0.0',
            '172.16.0.1' => '172.16.0.0',
            '255.255.255.255' => '255.255.255.0',
        ];

        foreach ($ips as $original => $expected) {
            $obfuscated = IpObfuscator::obfuscate($original, 'mask');
            $this->assertEquals($expected, $obfuscated);
        }
    }

    public function test_ipv6_with_port_notation()
    {
        // Some systems might provide IPv6 with port like [::1]:8080
        // We should handle this gracefully
        $ip = '::1';
        $obfuscated = IpObfuscator::obfuscate($ip, 'mask');

        $this->assertEquals('::', $obfuscated);
    }
}
