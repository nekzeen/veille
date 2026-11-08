<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\Source;
use App\Models\Veille;
use App\Services\Exclusions\ArticleVisibility;
use App\Services\VeilleRematcher;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Requêtes de lecture des articles d'une veille (conception M1.12 §3).
 *
 * Point d'entrée unique : `base()` part de `$veille->articles()` —
 * le `user_id` du pivot `article_veille` est injecté par la relation
 * (tenant dénormalisé, garde-fou SQL) — et la fenêtre de rétention de
 * 6 mois s'applique partout (liste, options, voisins, accès direct).
 * Aucun `user_id` ne provient jamais de la requête HTTP.
 */
final class VeilleArticleQuery
{
    public const PER_PAGE = 20;

    /** Date effective partagée : publication, sinon collecte (§1.2.3). */
    private const EFFECTIVE_DATE = 'COALESCE(articles.published_at, articles.collected_at)';

    /**
     * Articles associés à la veille et dans la fenêtre de rétention.
     * `with('source')` : nom de source joint — pas de N+1.
     *
     * @return BelongsToMany<Article, Veille>
     */
    private function base(Veille $veille): BelongsToMany
    {
        $cutoff = now('UTC')->subMonths(VeilleRematcher::RETENTION_MONTHS);

        return $veille->articles()
            ->with('source')
            // Archivés masqués du flux veille (§4.E : l'archive masque,
            // ne protège pas) — accessibles via l'espace personnel.
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('article_user')
                ->whereColumn('article_user.article_id', 'articles.id')
                ->where('article_user.user_id', $veille->user_id)
                ->where('article_user.is_archived', true))
            ->where(fn ($q) => ArticleVisibility::apply($q, $veille->user_id, 'articles.id'))
            ->whereRaw(self::EFFECTIVE_DATE.' >= ?', [$cutoff]);
    }

    /**
     * base + filtres validés par le contrôleur (liste blanche §4).
     * `wherePivot` s'applique DIRECTEMENT sur la relation — via
     * `when`, le callback reçoit le Builder sous-jacent où
     * `wherePivot` devient un where dynamique `pivot = ?` (vérifié).
     *
     * @return BelongsToMany<Article, Veille>
     */
    private function filtered(Veille $veille, ?int $sourceId, ?string $keyword, string $state = 'all'): BelongsToMany
    {
        $query = $this->base($veille);
        if ($sourceId !== null) {
            $query->where('articles.source_id', $sourceId);
        }
        if ($keyword !== null) {
            $query->wherePivot('matched_keyword', $keyword);
        }

        // Filtre de lecture (M11.2) — statut GLOBAL par (user, article)
        // via LEFT JOIN article_user, même pattern que les compteurs
        // dashboard : indexé, sans N+1. Par défaut 'all' (navigation
        // prev/next inchangée).
        if ($state === 'unread' || $state === 'read') {
            $query->leftJoin('article_user', function ($join) use ($veille): void {
                $join->on('article_user.article_id', '=', 'articles.id')
                    ->where('article_user.user_id', '=', $veille->user_id);
            });
            if ($state === 'unread') {
                $query->where(fn ($q) => $q->whereNull('article_user.is_read')
                    ->orWhere('article_user.is_read', false));
            } else {
                $query->where('article_user.is_read', true);
            }
        }

        return $query;
    }

    /** Liste paginée — tri stable portable. Les paramètres d'URL validés sont ajoutés par le contrôleur (`appends`). */
    public function paginate(Veille $veille, ?int $sourceId, ?string $keyword, string $state = 'unread'): LengthAwarePaginator
    {
        return $this->filtered($veille, $sourceId, $keyword, $state)
            ->orderByRaw(self::EFFECTIVE_DATE.' DESC')
            ->orderByDesc('articles.id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Article associé à la veille et dans la fenêtre — `null` si non
     * associé, rattaché à un autre utilisateur (pivot `user_id`) ou
     * hors rétention : le contrôleur rend 404 (jamais 403).
     */
    /**
     * Articles NON LUS de la veille, périmètre complet : rétention 6
     * mois + exclusions M9 + non-archivés (hérité de `base()`), statut
     * global (user_id, article_id). Utilisé par « Tout marquer lu ».
     *
     * @return BelongsToMany<Article, Veille>
     */
    public function unreadQuery(Veille $veille): BelongsToMany
    {
        return $this->filtered($veille, null, null, 'unread');
    }

    public function findArticle(Veille $veille, int $articleId): ?Article
    {
        return $this->base($veille)
            ->where('articles.id', $articleId)
            ->first();
    }

    /**
     * Sources réellement présentes dans le périmètre — pas les sources
     * théoriques de la veille (pas d'option de filtre sans résultat).
     *
     * @return Collection<int, Source>
     */
    public function filterSources(Veille $veille): Collection
    {
        $ids = $this->base($veille)
            ->reorder()
            ->distinct()
            ->pluck('articles.source_id');

        return Source::whereIn('id', $ids)->orderBy('name')->get();
    }

    /** Mots-clés ayant réellement déclenché des correspondances dans le périmètre. */
    public function matchedKeywords(Veille $veille): Collection
    {
        return $this->base($veille)
            ->wherePivotNotNull('matched_keyword')
            ->reorder()
            ->distinct()
            ->orderBy('article_veille.matched_keyword')
            ->pluck('article_veille.matched_keyword');
    }

    /**
     * Voisin plus récent — comparaison de curseur sur
     * (date effective, id) dans l'ensemble filtré ; `first()` renvoie
     * null en bordure (pas de scan ni de chargement mémoire).
     */
    public function previous(Veille $veille, Article $article, ?int $sourceId, ?string $keyword): ?Article
    {
        $cursor = $article->published_at ?? $article->collected_at;

        return $this->filtered($veille, $sourceId, $keyword)
            ->whereRaw(
                '('.self::EFFECTIVE_DATE.' > ?'
                .' OR ('.self::EFFECTIVE_DATE.' = ? AND articles.id > ?))',
                [$cursor, $cursor, $article->id])
            ->orderByRaw(self::EFFECTIVE_DATE.' ASC')
            ->orderBy('articles.id')
            ->first();
    }

    /** Voisin plus ancien — condition symétrique, même ordre que la liste. */
    public function next(Veille $veille, Article $article, ?int $sourceId, ?string $keyword): ?Article
    {
        $cursor = $article->published_at ?? $article->collected_at;

        return $this->filtered($veille, $sourceId, $keyword)
            ->whereRaw(
                '('.self::EFFECTIVE_DATE.' < ?'
                .' OR ('.self::EFFECTIVE_DATE.' = ? AND articles.id < ?))',
                [$cursor, $cursor, $article->id])
            ->orderByRaw(self::EFFECTIVE_DATE.' DESC')
            ->orderByDesc('articles.id')
            ->first();
    }
}
