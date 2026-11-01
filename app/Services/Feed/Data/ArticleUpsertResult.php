<?php

namespace App\Services\Feed\Data;

/**
 * Bilan d'un lot d'articles persistés pour une source — DTO sans
 * logique, exploitable par le futur orchestrateur (compteurs du log
 * INFO de Collecteur_RSS_Architecture §8).
 *
 * - `received` : items fournis (valides ou non) ;
 * - `created` : lignes insérées ;
 * - `updated` : lignes existantes modifiées (contenu, URL ou GUID) ;
 * - `unchanged` : lignes retrouvées identiques ;
 * - `skipped` : items invalides ignorés (URL ou titre absent) ;
 * - `collisions` : anomalies de déduplication journalisées (url_hash
 *   ambigu, collision de `dedup_key`) — jamais de fusion.
 */
final class ArticleUpsertResult
{
    public function __construct(
        public readonly int $received,
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $skipped,
        public readonly int $collisions,
    ) {}
}
