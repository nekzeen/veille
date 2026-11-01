<?php

namespace App\Services\Feed;

use App\Services\Feed\Data\ValidatedFeedUrl;
use App\Services\Feed\Exceptions\FeedUrlException;

/**
 * Validation syntaxique d'une URL de flux avant toute E/S (§4.G) :
 * schéma http/https uniquement, ports 80/443, aucun identifiant intégré,
 * hôte non vide, IDN converti en punycode, rejet des formes numériques
 * ambiguës que la libc pourrait réinterpréter comme des IP
 * (`2130706433`, `0x7f000001`, `0177.0.0.1`…).
 *
 * L'adresse IP littérale est acceptée ici — c'est
 * {@see IpAddressPolicy} qui la rejettera en aval.
 */
final class FeedUrlValidator
{
    private const MAX_LENGTH = 2048;

    public function validate(string $url): ValidatedFeedUrl
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            throw new FeedUrlException('URL vide ou excessivement longue.');
        }
        if (preg_match('/[\x00-\x20]/', $url) === 1) {
            throw new FeedUrlException('URL contenant des caractères de contrôle.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new FeedUrlException('URL malformée.');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new FeedUrlException("Protocole refusé : {$scheme}.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FeedUrlException('Identifiants intégrés dans l\'URL refusés.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port !== 80 && $port !== 443) {
            throw new FeedUrlException("Port refusé : {$port}.");
        }

        $host = $this->normalizeHost(trim($parts['host'], '[]'));

        $displayHost = str_contains($host, ':') ? "[{$host}]" : $host;
        $uri = $scheme.'://'.$displayHost
            .(isset($parts['port']) ? ":{$port}" : '')
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');

        return new ValidatedFeedUrl($scheme, $host, $port, $uri);
    }

    /**
     * Hôte en forme exploitable : IPv6 sans crochets, IP littérale
     * inchangée, nom IDN en punycode minuscules.
     */
    private function normalizeHost(string $host): string
    {
        if ($host === '') {
            throw new FeedUrlException('Nom d\'hôte absent.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || $ascii === '') {
            throw new FeedUrlException("Nom d'hôte invalide : {$host}.");
        }

        $host = strtolower($ascii);

        // Formes qu'inet_aton de la libc pourrait réinterpréter en IPv4
        // sans être un littéral IP valide : 1 à 4 parties numériques
        // séparées par des points (décimal, octal préfixé 0, hexa 0x,
        // mixtes) — 2130706433, 0x7f000001, 0177.0.0.1, 0x7f.1, 127.1…
        // Les noms composés de lettres hex (cafe.de, dead.beef…) ne
        // sont PAS concernés : inet_aton ne sait pas les lire.
        $parts = explode('.', $host);
        $numeric = true;
        foreach ($parts as $part) {
            if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/i', $part) !== 1) {
                $numeric = false;
                break;
            }
        }
        if ($numeric) {
            throw new FeedUrlException("Représentation d'hôte ambiguë : {$host}.");
        }

        // Nom mono-label (deadbeef, localhost, intranet…) : jamais un
        // hôte public de flux, il pourrait se résoudre via les
        // suffixes de recherche du résolveur local vers une cible
        // interne — rejeté en amont.
        if (count($parts) === 1) {
            throw new FeedUrlException("Nom d'hôte sans domaine : {$host}.");
        }

        return $host;
    }
}
