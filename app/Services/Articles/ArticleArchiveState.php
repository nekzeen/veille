<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\User;
use App\Services\Exclusions\ArticleVisibility;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Archivage personnel des articles (DataModel §3.9, §4.E).
 *
 * `is_archived=1` **masque** l'article des listes normales (veilles,
 * favoris) sans jamais le protéger de la purge à 6 mois — l'archive
 * seule n'est pas protectrice. Opérations idempotentes ; la ligne
 * `article_user` n'est supprimée que si `hasPersonalDataBesides` est
 * vide (règle centralisée §3.9).
 */
final class ArticleArchiveState
{
    public const PER_PAGE = 20;

    /** Non archivé si pas de ligne ou `is_archived` faux. */
    public function isArchived(User $user, Article $article): bool
    {
        return (bool) ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->value('is_archived');
    }

    /** Archiver : upsert idempotent, autres données préservées. */
    public function archive(User $user, Article $article): void
    {
        ArticleUser::updateOrCreate(
            ['user_id' => $user->id, 'article_id' => $article->id],
            ['is_archived' => true],
        );
    }

    /**
     * Désarchiver : `is_archived` repasse à 0 ; si la ligne ne porte
     * plus aucune donnée personnelle, elle est supprimée (§3.9).
     */
    public function unarchive(User $user, Article $article): void
    {
        $row = ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->first();

        if ($row === null) {
            return;
        }

        if (! $row->hasPersonalDataBesides(['is_archived'])) {
            $row->delete();

            return;
        }

        $row->is_archived = false;
        $row->save();
    }

    /**
     * « Mes archives » : liste paginée, date effective DESC puis id
     * DESC — accès autonome, indépendant de `article_veille`.
     */
    public function archived(User $user): LengthAwarePaginator
    {
        return Article::query()
            ->join('article_user', fn ($join) => $join
                ->on('article_user.article_id', '=', 'articles.id')
                ->where('article_user.user_id', $user->id)
                ->where('article_user.is_archived', true))
            ->where(fn ($q) => ArticleVisibility::apply($q, $user->id, 'articles.id'))
            ->with('source')
            ->select('articles.*')
            ->orderByRaw('COALESCE(articles.published_at, articles.collected_at) DESC')
            ->orderByDesc('articles.id')
            ->paginate(self::PER_PAGE);
    }
}
