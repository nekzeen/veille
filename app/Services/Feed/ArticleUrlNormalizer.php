<?php

namespace App\Services\Feed;

/**
 * Canonisation « agressive bornée » des URL d'articles (DataModel
 * §4.B.2) — sert uniquement à reconnaître « le même article » pour
 * `url_hash` et la chaîne `dedup_key` ; l'URL stockée dans
 * `articles.url` reste l'originale du flux.
 *
 * Règles appliquées (aucune autre) : schéma et hôte en minuscules,
 * port par défaut retiré, fragment supprimé, paramètres de tracking
 * retirés (liste bornée), `www.` initial de l'hôte supprimé.
 * Aucun réordonnancement, aucun décodage, aucune requête HTTP —
 * déterministe.
 */
final class ArticleUrlNormalizer
{
    /** Préfixes de paramètres de tracking retirés (§4.B.2). */
    private const TRACKING_PREFIXES = ['utm_', 'mc_'];

    /** Paramètres de tracking exacts retirés (§4.B.2). */
    private const TRACKING_NAMES = ['fbclid', 'gclid'];

    /**
     * URL normalisée pour la comparaison, ou `null` si l'entrée est
     * nulle, vide ou ne contient pas schéma + hôte analysables —
     * l'appelant décide alors du repli (§4.B.3).
     */
    public function normalize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if ($host === '') {
            return null;
        }

        $port = $parts['port'] ?? null;
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $user = isset($parts['user'])
            ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@'
            : '';

        $normalized = $scheme.'://'.$user.$host.($port !== null ? ':'.$port : '')
            .($parts['path'] ?? '');

        if (isset($parts['query'])) {
            $query = $this->filterTrackingParams($parts['query']);
            if ($query !== '') {
                $normalized .= '?'.$query;
            }
        }

        return $normalized;
    }

    /**
     * Retire les paramètres de tracking en conservant l'ordre et la
     * forme brute (encodage inclus) des paramètres métier — aucun
     * réordonnancement, aucune réécriture (§4.B.2).
     */
    private function filterTrackingParams(string $query): string
    {
        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower((string) strstr($pair, '=', true) ?: $pair);
            if (in_array($name, self::TRACKING_NAMES, true)) {
                continue;
            }
            $prefixed = false;
            foreach (self::TRACKING_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $prefixed = true;
                    break;
                }
            }
            if (! $prefixed) {
                $kept[] = $pair;
            }
        }

        return implode('&', $kept);
    }
}
