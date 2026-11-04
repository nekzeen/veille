<?php

declare(strict_types=1);

namespace App\Services\Exclusions;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Filtre de visibilité des articles pour un utilisateur (M9 §9.1) :
 * `NOT EXISTS` sur `article_user_hidden` joint aux règles encore
 * actives. Indexé par la PK naturelle (user_id, article_id) — aucun
 * balayage.
 */
final class ArticleVisibility
{
    /**
     * Exclut les articles masqués pour l'utilisateur donné.
     *
     * @param  string  $articleColumn  colonne qualifiée d'id d'article
     *                                 ('articles.id' ou 'article_veille.article_id')
     */
    public static function apply(EloquentBuilder|QueryBuilder $query, int $userId, string $articleColumn): void
    {
        $query->whereNotExists(function ($sub) use ($userId, $articleColumn): void {
            $sub->select(DB::raw(1))
                ->from('article_user_hidden as h')
                ->whereColumn('h.article_id', $articleColumn)
                ->where('h.user_id', $userId)
                ->where(function ($w): void {
                    $w->whereNull('h.exclusion_id')
                        ->orWhereExists(function ($e): void {
                            $e->select(DB::raw(1))
                                ->from('user_exclusions as ue')
                                ->whereColumn('ue.id', 'h.exclusion_id')
                                ->where('ue.is_active', true);
                        });
                });
        });
    }
}
