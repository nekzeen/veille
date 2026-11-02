<?php

namespace App\Services\Data;

/**
 * Bilan du matching d'un article — DTO sans logique (même convention
 * que Feed\Data\ArticleUpsertResult).
 *
 * - `examined` : veilles candidates (actives, contenant la source,
 *   appartenant à un abonné de la source) ;
 * - `matched` : veilles dont au moins un mot-clé correspond ;
 * - `created` : correspondances insérées dans `article_veille` ;
 * - `existing` : correspondances déjà présentes — idempotence ou
 *   doublon concurrent absorbé (PK veille_id+article_id).
 */
final class ArticleMatchResult
{
    public function __construct(
        public readonly int $examined,
        public readonly int $matched,
        public readonly int $created,
        public readonly int $existing,
    ) {}
}
