<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Veille;
use App\Services\Exclusions\ExclusionMatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Point unique d'écriture des correspondances `article_veille`
 * (DataModel §3.8/§3.11). Toute insertion ou suppression verrouille
 * d'abord la ligne `veilles` par SELECT ... FOR UPDATE et conserve
 * le verrou jusqu'au commit : identifiants `article_veille.id`
 * attribués uniquement sous le verrou ⇒ ordre de verrou = ordre
 * d'id = ordre de commit par veille, ce qui rend le watermark de
 * génération des digests étanche (aucune insertion non commitée ne
 * peut exister sans détenir le verrou).
 *
 * Appelé dans une transaction englobante, `DB::transaction` devient
 * un savepoint : le verrou ligne est conservé jusqu'au commit de la
 * transaction externe (jamais libéré prématurément).
 */
final class ArticleVeilleWriter
{
    public function __construct(private readonly ExclusionMatcher $exclusions) {}

    /**
     * Verrouille la ligne de la veille (SELECT ... FOR UPDATE). Doit
     * précéder toute écriture sur ses correspondances.
     */
    /**
     * Justifications de masquage (M9 §9.3) : toutes les règles actives
     * de l'utilisateur correspondant à l'article → une ligne chacune
     * dans `article_user_hidden`, idempotent (`insertOrIgnore`), dans
     * la même transaction que l'insertion `article_veille`.
     */
    private function recordExclusions(Veille $veille, int $articleId): void
    {
        $article = Article::query()
            ->find($articleId, ['id', 'url', 'title', 'summary', 'content']);
        if ($article === null) {
            return;
        }

        $haystack = implode(' ', array_filter([
            $article->title, $article->summary, $article->content,
        ]));

        $exclusionIds = $this->exclusions->justifications(
            $veille->user_id, $article->url, $haystack,
        );
        if ($exclusionIds === []) {
            return;
        }

        $now = now('UTC');
        DB::table('article_user_hidden')->insertOrIgnore(array_map(
            fn (int $exclusionId): array => [
                'user_id' => $veille->user_id,
                'article_id' => $articleId,
                'exclusion_id' => $exclusionId,
                'created_at' => $now,
            ],
            $exclusionIds,
        ));
    }

    private function lockVeille(int $veilleId): void
    {
        Veille::whereKey($veilleId)->lockForUpdate()->value('id');
    }

    /**
     * Enregistre une correspondance sous verrou. Retourne true si la
     * ligne est créée, false si elle existait déjà (doublon
     * concurrent absorbé via la PK composite).
     */
    public function record(Veille $veille, int $articleId, ?string $matchedKeyword): bool
    {
        return DB::transaction(function () use ($veille, $articleId, $matchedKeyword): bool {
            $this->lockVeille($veille->id);

            try {
                ArticleVeille::create([
                    'veille_id' => $veille->id,
                    'article_id' => $articleId,
                    'user_id' => $veille->user_id,
                    'matched_keyword' => $matchedKeyword,
                ]);

                $this->recordExclusions($veille, $articleId);

                return true;
            } catch (QueryException $e) {
                if (! SourceSubscriptionService::isUniqueViolation($e)) {
                    throw $e;
                }

                return false;
            }
        });
    }

    /**
     * Supprime les correspondances d'une veille pour les articles
     * donnés, sous le même verrou que les insertions.
     *
     * @param  list<int>  $articleIds
     */
    public function deleteFor(Veille $veille, array $articleIds): int
    {
        return DB::transaction(function () use ($veille, $articleIds): int {
            $this->lockVeille($veille->id);

            $deleted = ArticleVeille::where('veille_id', $veille->id)
                ->whereIn('article_id', $articleIds)->delete();

            // M12.21 — dater atomiquement le dernier détachement :
            // les articles qui n'ont plus AUCUNE association reçoivent
            // leur last_detached_at maintenant (grâce 7 j de M12.11).
            if ($deleted > 0) {
                DB::table('articles')
                    ->whereIn('id', $articleIds)
                    ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('article_veille')
                        ->whereColumn('article_veille.article_id', 'articles.id'))
                    ->update(['last_detached_at' => now('UTC')]);
            }

            return $deleted;
        });
    }
}
