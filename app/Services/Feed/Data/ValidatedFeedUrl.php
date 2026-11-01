<?php

namespace App\Services\Feed\Data;

use App\Services\Feed\FeedUrlValidator;

/**
 * URL validée et canonisée par {@see FeedUrlValidator} :
 * schéma http/https, hôte en forme ASCII (IDN converti en punycode,
 * minuscules), port effectif 80/443, URI de requête reconstruite sans
 * identifiants ni fragment.
 */
final class ValidatedFeedUrl
{
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $uri,
    ) {}
}
