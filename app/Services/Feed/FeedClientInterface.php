<?php

namespace App\Services\Feed;

use App\Services\Feed\Data\FeedFetchResult;
use App\Services\Feed\Exceptions\FeedFetchException;

/**
 * Récupération HTTP sécurisée d'un flux RSS/Atom. Interface injectable
 * dans les futurs services d'orchestration (job de collecte).
 */
interface FeedClientInterface
{
    /**
     * GET conditionnel éventuel (`If-None-Match` / `If-Modified-Since`).
     *
     * @throws FeedFetchException URL refusée, DNS rejeté, erreur
     *                            réseau/TLS/timeout, code HTTP non
     *                            accepté (hors 2xx, 304 et redirections
     *                            suivies), corps > 5 Mio ou redirection
     *                            invalide.
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FeedFetchResult;
}
