<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\User;

/**
 * Droit d'accès personnel à un article (DataModel §4.D-bis).
 *
 * L'article est accessible à l'utilisateur s'il est associé à l'une de
 * ses veilles **ou** si une ligne `article_user` lui appartient :
 * favori, note, catégorie, archivage ou simple état de lecture
 * survivent ainsi à la disparition de la correspondance.
 */
final class ArticlePersonalAccess
{
    public function hasAccess(User $user, Article $article): bool
    {
        return ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->exists()
            || ArticleVeille::where('article_id', $article->id)
                ->whereHas('veille', fn ($q) => $q->where('user_id', $user->id))
                ->exists();
    }
}
