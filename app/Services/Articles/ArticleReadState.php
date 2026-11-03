<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * État de lecture personnel d'un article (DataModel §3.9).
 *
 * Sémantique : absence de ligne OU `is_read=0` = non lu ; `is_read=1`
 * = lu. Opérations idempotentes : répéter `markRead` ne crée pas de
 * doublon (PK composite), répéter `markUnread` reste sans effet.
 * `user_id` vient toujours de l'utilisateur authentifié.
 */
final class ArticleReadState
{
    /** Non lu si pas de ligne ou `is_read` faux. */
    public function isRead(User $user, Article $article): bool
    {
        return (bool) ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->value('is_read');
    }

    /**
     * Marquer lu : upsert sur la PK composite — pas de doublon, les
     * autres données personnelles éventuelles sont préservées.
     */
    public function markRead(User $user, Article $article): void
    {
        ArticleUser::updateOrCreate(
            ['user_id' => $user->id, 'article_id' => $article->id],
            ['is_read' => true],
        );
    }

    /**
     * Marquer non lu : si la ligne ne porte que l'état de lecture, elle
     * est supprimée (évite les lignes vides — §3.9) ; sinon `is_read`
     * passe à 0 et favoris/note/catégorie/archivage sont préservés.
     */
    public function markUnread(User $user, Article $article): void
    {
        $row = ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->first();

        if ($row === null) {
            return;
        }

        if (! $row->hasPersonalDataBesides(['is_read'])) {
            $row->delete();

            return;
        }

        $row->is_read = false;
        $row->save();
    }

    /**
     * États de lecture pour un ensemble d'articles — une requête
     * (liste paginée, pas de N+1). `id => is_read`.
     *
     * @param  iterable<int>  $articleIds
     * @return array<int, bool>
     */
    public function readMap(User $user, iterable $articleIds): array
    {
        $ids = Collection::wrap($articleIds)->all();
        if ($ids === []) {
            return [];
        }

        return ArticleUser::where('user_id', $user->id)
            ->whereIn('article_id', $ids)
            ->pluck('is_read', 'article_id')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }
}
