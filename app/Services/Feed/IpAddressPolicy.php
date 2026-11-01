<?php

namespace App\Services\Feed;

/**
 * Politique IP — allowlist (§4.G) : une adresse n'est admise que si
 * elle est globalement routable. Toute IP privée, réservée, spéciale,
 * de bouclage, link-local, multicast, de documentation ou de transition
 * est rejetée **par défaut** — seule la famille « Global » est admise.
 *
 * IPv4 : admis = tout sauf le registre IANA des usages spéciaux
 * (table explicite ci-dessous — `filter_var` NO_PRIV_RANGE/NO_RES_RANGE
 * est insuffisant : CGN, documentation et benchmark y échappent).
 *
 * IPv6 : admis = unicast globale `2000::/3` moins les blocs non globaux
 * internes (documentation, Teredo, 6to4, benchmark, NAT64, SRv6…).
 * Les IPv4-mappées (`::ffff:a.b.c.d`) sont normalisées en IPv4 **avant**
 * le test — pas de contournement de la règle IPv4 par une forme IPv6.
 */
final class IpAddressPolicy
{
    /** Plages IPv4 non globalement routables (registre IANA). */
    private const IPV4_NON_GLOBAL = [
        '0.0.0.0/8',       // this network
        '10.0.0.0/8',      // privé
        '100.64.0.0/10',   // CGNAT
        '127.0.0.0/8',     // loopback
        '169.254.0.0/16',  // link-local
        '172.16.0.0/12',   // privé
        '192.0.0.0/24',    // IETF protocol assignments
        '192.0.2.0/24',    // documentation TEST-NET-1
        '192.31.196.0/24', // AS112
        '192.52.193.0/24', // AMT
        '192.88.99.0/24',  // 6to4 relay (déprécié)
        '192.168.0.0/16',  // privé
        '192.175.48.0/24', // AS112
        '198.18.0.0/15',   // benchmark
        '198.51.100.0/24', // documentation TEST-NET-2
        '203.0.113.0/24',  // documentation TEST-NET-3
        '224.0.0.0/4',     // multicast
        '240.0.0.0/4',     // réservé
        '255.255.255.255/32', // broadcast
    ];

    /**
     * Blocs IPv6 non globaux à l'intérieur de l'unicast 2000::/3.
     *
     * `2001:20::/28` (ORCHIDv2, RFC 7343) et `2001:30::/28` (DRIP DETs,
     * RFC 9374) restent volontairement admis : le registre IANA les
     * marque « Globally Reachable » (vérifié en M1.10.3.2).
     * Les blocs hors 2000::/3 (100::/64, 64:ff9b::/96, 5f00::/16…) sont
     * listés par exhaustivité — la porte unicast les rejette déjà.
     */
    private const IPV6_NON_GLOBAL = [
        '64:ff9b::/96',    // NAT64 well-known (traduction)
        '64:ff9b:1::/48',  // NAT64 usage local
        '100::/64',        // discard
        '2001::/32',       // Teredo
        '2001:2::/48',     // benchmark
        '2001:10::/28',    // ORCHID (déprécié)
        '2001:db8::/32',   // documentation
        '2002::/16',       // 6to4
        '3fff::/20',       // documentation
        '5f00::/16',       // SRv6 SID
    ];

    /** Préfixe de la forme IPv4-mappée ::ffff:0:0/96. */
    private const MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /** @var array<int, array{0: string, 1: int}>|null */
    private static ?array $v4Ranges = null;

    /** @var array<int, array{0: string, 1: int}>|null */
    private static ?array $v6Ranges = null;

    public function isGloballyRoutable(string $ip): bool
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ! $this->inAnyRange($packed, self::$v4Ranges ??= self::ranges(self::IPV4_NON_GLOBAL));
        }

        // IPv4-mappée IPv6 → validée comme IPv4.
        if (substr($packed, 0, 12) === self::MAPPED_PREFIX) {
            return ! $this->inAnyRange(substr($packed, 12), self::$v4Ranges ??= self::ranges(self::IPV4_NON_GLOBAL));
        }

        // Unicast globale uniquement (2000::/3) hors blocs spéciaux.
        if ((ord($packed[0]) & 0xE0) !== 0x20) {
            return false;
        }

        return ! $this->inAnyRange($packed, self::$v6Ranges ??= self::ranges(self::IPV6_NON_GLOBAL));
    }

    /**
     * @param  array<int, string>  $cidrs
     * @return array<int, array{0: string, 1: int}>
     */
    private static function ranges(array $cidrs): array
    {
        $ranges = [];
        foreach ($cidrs as $cidr) {
            [$addr, $len] = explode('/', $cidr);
            $ranges[] = [(string) inet_pton($addr), (int) $len];
        }

        return $ranges;
    }

    /** @param  array<int, array{0: string, 1: int}>  $ranges */
    private function inAnyRange(string $packedIp, array $ranges): bool
    {
        foreach ($ranges as [$prefix, $len]) {
            if ($this->matchPrefix($packedIp, $prefix, $len)) {
                return true;
            }
        }

        return false;
    }

    private function matchPrefix(string $packedIp, string $prefix, int $len): bool
    {
        $fullBytes = intdiv($len, 8);
        if (strncmp($packedIp, $prefix, $fullBytes) !== 0) {
            return false;
        }

        $restBits = $len % 8;
        if ($restBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $restBits) & 0xFF;

        return (ord($packedIp[$fullBytes]) & $mask) === (ord($prefix[$fullBytes]) & $mask);
    }
}
