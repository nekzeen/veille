<?php

declare(strict_types=1);

namespace App\Services\Feed;

/**
 * Résolution du lien « réel » d'un article à l'entrée du pipeline :
 * les redirections Google (`google.com/url?url=…`) sont remplacées
 * par leur destination déclarée — sans requête HTTP, sans affaiblir
 * les protections SSRF (le résultat n'est pas une autorisation de
 * téléchargement). Destination absente, ambiguë ou invalide →
 * le lien original est conservé, jamais une URL fabriquée.
 *
 * Distinct d'`ArticleUrlNormalizer` (canonisation §4.B.2, pour
 * `url_hash`/`dedup_key`) : ici l'URL stockée elle-même change.
 */
final class ArticleLinkResolver
{
    /** @return non-empty-string|null */
    public static function resolve(?string $url): ?string
    {
        $url = $url === null ? '' : trim($url);
        if ($url === '') {
            return null;
        }

        // Entités HTML une seule fois (&amp; dans les liens de flux).
        if (str_contains($url, '&')) {
            $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded !== $url) {
                $url = $decoded;
            }
        }

        return self::googleRedirectTarget($url) ?? $url;
    }

    /** Cible d'une redirection Google (`/url`) : `url` puis `q`, http(s) seul. */
    private static function googleRedirectTarget(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (($host !== 'google.com' && $host !== 'www.google.com') || $path !== '/url') {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $params);
        $candidates = [];
        foreach (['url', 'q'] as $key) {
            $value = $params[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $candidates[$key] = $value;
            }
        }

        if (count($candidates) !== 1) {
            return null; // absent ou ambigu (url+q) → conserver l'original
        }

        $target = reset($candidates);
        $targetParts = parse_url($target);
        $scheme = strtolower((string) ($targetParts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)
            || trim((string) ($targetParts['host'] ?? '')) === '') {
            return null;
        }

        return $target;
    }
}
