<?php

declare(strict_types=1);

namespace App\Services\Shares;

use App\Models\Article;
use App\Models\Veille;
use App\Models\VeilleShare;
use App\Services\Exclusions\ArticleVisibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Lectures publiques bornées au périmètre du partage (M10 §4/§5) :
 * même date effective et même rétention que VeilleArticleQuery, filtre
 * ArticleVisibility du PROPRIÉTAIRE, aucun champ pivot/privé exposé.
 */
final class PublicShareQuery
{
    private const EFFECTIVE_DATE = 'COALESCE(articles.published_at, articles.collected_at)';

    private const RETENTION_DAYS = 15;

    private const PER_PAGE = 20;

    /**
     * Veilles du périmètre : single → la veille seule ; all →
     * recalculé à chaque appel (dynamique : créées/supprimées suivies).
     *
     * @return Collection<int, Veille>
     */
    public function veilles(VeilleShare $share): Collection
    {
        if (! $share->isGlobal()) {
            return $share->veille ? collect([$share->veille]) : collect();
        }

        return Veille::where('user_id', $share->user_id)
            ->where('is_active', true)->orderBy('name')->get();
    }

    /** Veille du périmètre ou null (jamais hors-scope → 404 contrôleur). */
    public function findVeille(VeilleShare $share, int $veilleId): ?Veille
    {
        if (! $share->isGlobal()) {

            return (int) $share->veille_id === $veilleId ? $share->veille : null;
        }

        return Veille::where('user_id', $share->user_id)->find($veilleId);
    }

    public function articles(Veille $veille): LengthAwarePaginator
    {
        return $this->base($veille)->paginate(self::PER_PAGE);
    }

    /** Article dans le périmètre du partage (pas seulement la veille). */
    public function findArticle(VeilleShare $share, int $articleId): ?Article
    {
        $q = Article::query()
            ->join('article_veille', fn ($j) => $j
                ->on('article_veille.article_id', '=', 'articles.id')
                ->where('article_veille.user_id', $share->user_id)
                ->when(! $share->isGlobal(), fn ($w) => $w
                    ->where('article_veille.veille_id', $share->veille_id)))
            ->where('articles.id', $articleId)
            ->whereRaw(self::EFFECTIVE_DATE.' >= ?', [now('UTC')->subDays(self::RETENTION_DAYS)])
            ->where(fn ($w) => ArticleVisibility::apply($w, $share->user_id, 'articles.id'))
            ->select('articles.*')
            ->with('source:id,name,site_url');

        return $q->first();
    }

    private function base(Veille $veille): BelongsToMany
    {
        return $veille->articles()
            ->where('article_veille.user_id', $veille->user_id)
            ->whereRaw(self::EFFECTIVE_DATE.' >= ?', [now('UTC')->subDays(self::RETENTION_DAYS)])
            ->where(fn ($q) => ArticleVisibility::apply($q, $veille->user_id, 'articles.id'))
            ->select('articles.id', 'articles.title', 'articles.summary', 'articles.url',
                'articles.source_id', 'articles.published_at', 'articles.collected_at')
            ->with('source:id,name,site_url')
            ->orderByRaw(self::EFFECTIVE_DATE.' DESC')
            ->orderByDesc('articles.id');
    }
}
