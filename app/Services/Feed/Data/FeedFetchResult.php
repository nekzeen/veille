<?php

namespace App\Services\Feed\Data;

/**
 * Résultat typé d'une récupération de flux — DTO sans logique.
 *
 * - `body` : contenu XML brut (chaîne vide si 304 ou corps vide) ;
 * - `statusCode` : code HTTP final ;
 * - `url` : URL initiale demandée ;
 * - `effectiveUrl` : URL finale après redirections éventuelles ;
 * - `etag` / `lastModified` : validateurs HTTP de la réponse (à stocker
 *   pour le GET conditionnel suivant — décision d'orchestration) ;
 * - `notModified` : vrai si la réponse finale est 304 ;
 * - `bytesRead` : octets de corps effectivement lus ;
 * - `redirects` : nombre de redirections suivies (0 à 3).
 */
final class FeedFetchResult
{
    public function __construct(
        public readonly string $body,
        public readonly int $statusCode,
        public readonly string $url,
        public readonly string $effectiveUrl,
        public readonly ?string $etag,
        public readonly ?string $lastModified,
        public readonly bool $notModified,
        public readonly int $bytesRead,
        public readonly int $redirects,
    ) {}
}
