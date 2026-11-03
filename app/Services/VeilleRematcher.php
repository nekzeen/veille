<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Veille;
use App\Services\Data\VeilleRematchResult;
use Illuminate\Support\Facades\DB;

/**
 * Rematching ciblé d'une veille (DataModel §4.D) : recalcul des
 * correspondances par différence d'ensembles sur la fenêtre de
 * rétention (6 mois, COALESCE(published_at, collected_at)).
 *
 * - attendue ∩ existante → conservée telle quelle (created_at et
 *   matched_keyword historiques préservés) ;
 * - attendue ∖ existante → insérée (created_at = maintenant) ;
 * - existante ∖ attendue → supprimée.
 *
 * Veille inactive : aucune écriture — l'historique est conservé
 * (D11) ; le recalcul intervient à la réactivation. Transaction
 * bornée à la veille ; un doublon concurrent (job de collecte) est
 * absorbé via la PK, les autres erreurs SQL sont propagées.
 */
final class VeilleRematcher
{
    public const RETENTION_MONTHS = 6;

    public function __construct(
        private readonly KeywordMatcher $matcher,
        private readonly ArticleVeilleWriter $writer,
    ) {}

    public function rematch(Veille $veille): VeilleRematchResult
    {
        $veille->refresh();
        if (! $veille->is_active) {
            return VeilleRematchResult::empty();
        }

        // Sources éligibles : associées à la veille ET toujours
        // couvertes par l'abonnement du propriétaire (§3.6/§5).
        $sourceIds = $veille->sources()->pluck('sources.id');
        $eligibleSourceIds = $veille->user()->firstOrFail()->userSources()
            ->whereIn('source_id', $sourceIds)->pluck('source_id');

        $keywords = $veille->keywords()->orderBy('created_at')->orderBy('id')->get();

        // Ensemble désiré : article_id => matched_keyword.
        $desired = [];
        $examined = 0;
        if ($eligibleSourceIds->isNotEmpty() && $keywords->isNotEmpty()) {
            $cutoff = now('UTC')->subMonths(self::RETENTION_MONTHS);
            Article::whereIn('source_id', $eligibleSourceIds)
                ->whereRaw('COALESCE(published_at, collected_at) >= ?', [$cutoff])
                ->orderBy('id')
                ->chunkById(200, function ($articles) use (&$desired, &$examined, $keywords): void {
                    foreach ($articles as $article) {
                        $examined++;
                        $term = $this->matcher->matchedTerm($article, $keywords);
                        if ($term !== null) {
                            $desired[$article->id] = $term;
                        }
                    }
                });
        }

        $existing = ArticleVeille::where('veille_id', $veille->id)
            ->pluck('article_id');

        $toCreate = array_diff_key($desired, $existing->flip()->all());
        $toDelete = $existing->diff(array_keys($desired));

        // Toutes les écritures passent par ArticleVeilleWriter : le
        // verrou `veilles` est acquis à la première écriture de la
        // transaction et conservé jusqu'à son commit (watermark des
        // digests étanche, §3.11).
        $created = 0;
        DB::transaction(function () use ($veille, $toDelete, $toCreate, &$created): void {
            if ($toDelete->isNotEmpty()) {
                $this->writer->deleteFor($veille, $toDelete->all());
            }

            foreach ($toCreate as $articleId => $term) {
                // Doublon concurrent (job de collecte) absorbé : false.
                if ($this->writer->record($veille, $articleId, $term)) {
                    $created++;
                }
            }
        });

        return new VeilleRematchResult(
            examined: $examined,
            expected: count($desired),
            kept: count($desired) - $created,
            created: $created,
            deleted: $toDelete->count(),
        );
    }
}
