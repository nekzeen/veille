<?php

namespace App\Services\Alerts;

use App\Models\ArticleVeille;
use App\Models\Veille;
use App\Models\VeilleAlert;
use App\Services\ArticleVeilleWriter;
use Illuminate\Support\Collection;

/**
 * Fenêtre de génération des digests (DataModel §3.11). Le curseur
 * mécanique est `article_veille.id` — ordre total, sans égalité
 * possible : aucune perte ni doublon par timestamp identique.
 *
 * - ouverture : end_match_id du dernier digest `sent` (0 au premier) ;
 * - watermark : MAX(id) sous verrou de la veille — borne d'éligibilité
 *   éphémère, jamais enregistrée comme clôture ;
 * - clôture : id du dernier élément réellement sélectionné.
 */
final class DigestWindowCalculator
{
    public const MAX_ARTICLES = 50;

    /** Curseur d'ouverture du prochain digest de la veille. */
    public function openCursor(Veille $veille): int
    {
        return (int) (VeilleAlert::where('veille_id', $veille->id)
            ->where('status', VeilleAlert::STATUS_SENT)
            ->max('end_match_id') ?? 0);
    }

    /**
     * Watermark de génération : MAX(article_veille.id) de la veille.
     * Doit être appelé sous le verrou de la veille — sous le protocole
     * {@see ArticleVeilleWriter}, aucune insertion non
     * commitée ne peut exister sans détenir ce verrou.
     */
    public function watermark(Veille $veille): int
    {
        return (int) ArticleVeille::where('veille_id', $veille->id)->max('id');
    }

    /**
     * Correspondances éligibles de la fenêtre ]startMatchId, watermark],
     * triées par id, plafonnées à {@see self::MAX_ARTICLES}.
     *
     * @return Collection<int, ArticleVeille>
     */
    public function select(Veille $veille, int $startMatchId, int $watermark): Collection
    {
        return ArticleVeille::where('veille_id', $veille->id)
            ->where('id', '>', $startMatchId)
            ->where('id', '<=', $watermark)
            ->orderBy('id')
            ->limit(self::MAX_ARTICLES)
            ->get();
    }
}
