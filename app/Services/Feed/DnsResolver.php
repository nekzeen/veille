<?php

namespace App\Services\Feed;

/**
 * Résolveur DNS réel : enregistrements A + AAAA via le résolveur
 * système. Retourne les adresses brutes — aucune validation n'est
 * faite ici, c'est le rôle de {@see IpAddressPolicy}.
 */
final class DnsResolver implements IpResolverInterface
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
