<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\User;
use App\Services\Exclusions\ArticleVisibility;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Favoris personnels des articles (DataModel §3.9, §4.D-bis).
 *
 * `is_favorite=1` ouvre un **droit d'accès autonome** : le favori
 * survit à la disparition de la correspondance `article_veille` et
 * protège l'article de la purge (§4.E). Opérations idempotentes ;
 * `user_id` vient toujours de l'utilisateur authentifié.
 */
final class ArticleFavoriteState
{
    public const PER_PAGE = 20;

    /** Non favori si pas de ligne ou `is_favorite` faux. */
    public function isFavorite(User $user, Article $article): bool
    {
        return (bool) ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->value('is_favorite');
    }

    /** Marquer favori : upsert idempotent sur la PK composite. */
    public function markFavorite(User $user, Article $article): void
    {
        ArticleUser::updateOrCreate(
            ['user_id' => $user->id, 'article_id' => $article->id],
            ['is_favorite' => true],
        );
    }

    /**
     * Retirer le favori : `is_favorite` repasse à 0 en préservant les
     * autres données personnelles ; si la ligne ne porte plus rien
     * d'autre (état de lecture, note, catégorie, archivage), elle est
     * supprimée — pas de ligne vide conservée.
     */
    public function removeFavorite(User $user, Article $article): void
    {
        $row = ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->first();

        if ($row === null) {
            return;
        }

        if (! $row->hasPersonalDataBesides(['is_favorite'])) {
            $row->delete();

            return;
        }

        $row->is_favorite = false;
        $row->save();
    }

    /**
     * `article_id => is_favorite` pour une liste — une requête.
     *
     * @param  iterable<int>  $articleIds
     * @return array<int, bool>
     */
    public function favoriteMap(User $user, iterable $articleIds): array
    {
        $ids = Collection::wrap($articleIds)->all();
        if ($ids === []) {
            return [];
        }

        return ArticleUser::where('user_id', $user->id)
            ->whereIn('article_id', $ids)
            ->pluck('is_favorite', 'article_id')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }

    /**
     * « Mes favoris » : liste paginée triée par date effective
     * (publication, collecte en repli) DESC puis id DESC — les
     * articles restent accessibles sans correspondance `article_veille`.
     */
    public function favorites(User $user): LengthAwarePaginator
    {
        return Article::query()
            ->join('article_user', fn ($join) => $join
                ->on('article_user.article_id', '=', 'articles.id')
                ->where('article_user.user_id', $user->id)
                ->where('article_user.is_favorite', true)
                ->where('article_user.is_archived', false))
            ->where(fn ($q) => ArticleVisibility::apply($q, $user->id, 'articles.id'))
            ->with('source')
            ->select('articles.*')
            ->orderByRaw('COALESCE(articles.published_at, articles.collected_at) DESC')
            ->orderByDesc('articles.id')
            ->paginate(self::PER_PAGE);
    }
}
