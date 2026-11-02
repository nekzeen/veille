<?php

namespace App\Services\Data;

/**
 * Bilan d'un rematching ciblé de veille (DataModel §4.D).
 *
 * - `examined` : articles éligibles examinés (sources de la veille
 *   ∩ abonnements du propriétaire, fenêtre 6 mois) ;
 * - `expected` : correspondances attendues (≥ 1 mot-clé) ;
 * - `kept` : associations déjà présentes et toujours valides,
 *   `created_at`/`matched_keyword` historiques préservés ;
 * - `created` : associations manquantes insérées ;
 * - `deleted` : associations devenues invalides supprimées.
 */
final class VeilleRematchResult
{
    public function __construct(
        public readonly int $examined,
        public readonly int $expected,
        public readonly int $kept,
        public readonly int $created,
        public readonly int $deleted,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0);
    }
}
