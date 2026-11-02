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
    /**
     * @param  list<int>  $articleIds  IDs des lignes `articles`
     *                                 correspondant aux items du lot (créées, mises à jour ou
     *                                 inchangées) — permet au matching de n'examiner que ces
     *                                 articles, sans relecture globale de la source.
     */
    public function __construct(
        public readonly int $received,
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $skipped,
        public readonly int $collisions,
        public readonly array $articleIds,
    ) {}
}
