<?php

namespace Tests\Unit;

use App\Services\Feed\IpAddressPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpAddressPolicy::class)]
class IpAddressPolicyTest extends TestCase
{
    private IpAddressPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new IpAddressPolicy;
    }

    #[DataProvider('globalIps')]
    public function test_global_unicast_accepted(string $ip): void
    {
        $this->assertTrue($this->policy->isGloballyRoutable($ip), "Devrait être admise : {$ip}");
    }

    /** @return array<string, array{0: string}> */
    public static function globalIps(): array
    {
        return [
            'v4 exemple' => ['93.184.216.34'],
            'v4 cloudflare' => ['1.1.1.1'],
            'v4 google dns' => ['8.8.8.8'],
            'v6 cloudflare' => ['2606:4700:4700::1111'],
            'v6 google' => ['2001:4860:4860::8888'],
            'ipv4-mappée vers v4 publique' => ['::ffff:8.8.8.8'],
            // IANA « Globally Reachable » : admis (vérifié M1.10.3.2).
            'v6 orchidv2 2001:20::/28 borne basse' => ['2001:20::1'],
            'v6 orchidv2 2001:20::/28 borne haute' => ['2001:2f:ffff:ffff:ffff:ffff:ffff:ffff'],
            'v6 drip det 2001:30::/28 borne basse' => ['2001:30::1'],
            'v6 drip det 2001:30::/28 borne haute' => ['2001:3f:ffff:ffff:ffff:ffff:ffff:ffff'],
            'v6 non alloué après 2001:3f' => ['2001:40::1'],
            'v6 amt 2001:3::/32' => ['2001:3::1'],
        ];
    }

    #[DataProvider('nonGlobalIps')]
    public function test_special_or_non_global_rejected(string $ip): void
    {
        $this->assertFalse($this->policy->isGloballyRoutable($ip), "Devrait être rejetée : {$ip}");
    }

    /** @return array<string, array{0: string}> */
    public static function nonGlobalIps(): array
    {
        return [
            // IPv4
            'v4 this-net' => ['0.0.0.0'],
            'v4 privée 10' => ['10.0.0.1'],
            'v4 privée 172.16' => ['172.16.0.1'],
            'v4 privée 172.31' => ['172.31.255.255'],
            'v4 privée 192.168' => ['192.168.1.1'],
            'v4 loopback' => ['127.0.0.1'],
            'v4 link-local metadata' => ['169.254.169.254'],
            'v4 cgnat' => ['100.64.0.1'],
            'v4 ietf' => ['192.0.0.9'],
            'v4 doc test-net-1' => ['192.0.2.1'],
            'v4 doc test-net-2' => ['198.51.100.1'],
            'v4 doc test-net-3' => ['203.0.113.1'],
            'v4 benchmark' => ['198.18.0.1'],
            'v4 6to4 relay' => ['192.88.99.1'],
            'v4 multicast' => ['224.0.0.1'],
            'v4 réservée' => ['240.0.0.1'],
            'v4 broadcast' => ['255.255.255.255'],
            // IPv6
            'v6 loopback' => ['::1'],
            'v6 non spécifiée' => ['::'],
            'v6 link-local' => ['fe80::1'],
            'v6 ula' => ['fd00::1'],
            'v6 ula fc' => ['fc12:34::1'],
            'v6 multicast' => ['ff02::1'],
            'v6 doc' => ['2001:db8::1'],
            'v6 teredo' => ['2001::1'],
            'v6 6to4' => ['2002:cb0a:3cdd:1::1'],
            'v6 benchmark' => ['2001:2::1'],
            'v6 orchid' => ['2001:10::1'],
            'v6 orchid déprécié borne haute' => ['2001:1f:ffff:ffff:ffff:ffff:ffff:ffff'],
            'v6 discard' => ['100::1'],
            'v6 dummy prefix rfc9780' => ['100:0:0:1::1'],
            'v6 nat64 local' => ['64:ff9b:1::1'],
            'v6 nat64 wkp' => ['64:ff9b::8.8.8.8'],
            'ipv4-mappée privée' => ['::ffff:10.0.0.1'],
            'ipv4-mappée loopback' => ['::ffff:127.0.0.1'],
            'ipv4-mappée link-local' => ['::ffff:169.254.169.254'],
            'ipv4-mappée doc' => ['::ffff:192.0.2.1'],
            // Non-IP
            'chaîne quelconque' => ['pas-une-ip'],
            'vide' => [''],
            'zone-id' => ['fe80::1%eth0'],
        ];
    }
}
