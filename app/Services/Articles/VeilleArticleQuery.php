<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\Source;
use App\Models\Veille;
use App\Services\VeilleRematcher;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
    private function filtered(Veille $veille, ?int $sourceId, ?string $keyword): BelongsToMany
    {
        $query = $this->base($veille);
        if ($sourceId !== null) {
            $query->where('articles.source_id', $sourceId);
        }
        if ($keyword !== null) {
            $query->wherePivot('matched_keyword', $keyword);
        }

        return $query;
    }

    /** Liste paginée — tri stable portable. Les paramètres d'URL validés sont ajoutés par le contrôleur (`appends`). */
    public function paginate(Veille $veille, ?int $sourceId, ?string $keyword): LengthAwarePaginator
    {
        return $this->filtered($veille, $sourceId, $keyword)
            ->orderByRaw(self::EFFECTIVE_DATE.' DESC')
            ->orderByDesc('articles.id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * Article associé à la veille et dans la fenêtre — `null` si non
     * associé, rattaché à un autre utilisateur (pivot `user_id`) ou
     * hors rétention : le contrôleur rend 404 (jamais 403).
     */
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
