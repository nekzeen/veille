<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Veille;
use App\Services\Articles\HtmlContentSanitizer;
use App\Services\Articles\VeilleArticleQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lecture des articles d'une veille (conception M1.12 §2).
 *
 * L'isolation est appliquée deux fois : `Gate::authorize('view')`
 * pour la veille, puis le `user_id` du pivot `article_veille` injecté
 * par la relation — jamais de valeur utilisateur issue de la requête.
 */
class VeilleArticleController extends Controller
{
    public function __construct(
        private readonly VeilleArticleQuery $articles,
        private readonly HtmlContentSanitizer $sanitizer,
    ) {}

    public function index(Request $request, Veille $veille): View
    {
        Gate::authorize('view', $veille);

        $params = self::navParams($request);

        $paginator = $this->articles
            ->paginate($veille, $params['source_id'] ?? null, $params['keyword'] ?? null)
            // `page` n'est jamais appensé : il figerait tous les liens.
            ->appends(array_diff_key($params, ['page' => true]));

        return view('veilles.articles.index', [
            'veille' => $veille,
            'articles' => $paginator,
            'filterSources' => $this->articles->filterSources($veille),
            'keywords' => $this->articles->matchedKeywords($veille),
            'params' => $params,
        ]);
    }

    public function show(Request $request, Veille $veille, Article $article): View
    {
        Gate::authorize('view', $veille);

        $params = self::navParams($request);

        // Association + isolation + rétention en une requête — 404
        // sinon (pas de confirmation d'existence).
        $article = $this->articles->findArticle($veille, $article->id)
            ?? abort(404);

        // Fallback (§5) : contenu assaini → résumé assaini → métadonnées.
        $body = $this->sanitizer->sanitize($article->content);
        if ($body === '') {
            $body = $this->sanitizer->sanitize($article->summary);
        }

        // Lien « original » : l'URL vient du flux — http(s) exigé à
        // l'affichage (javascript:/data: refusés, audit M1.12.5).
        $scheme = strtolower((string) parse_url($article->url, PHP_URL_SCHEME));
        $originalUrl = ($scheme === 'http' || $scheme === 'https')
            ? $article->url
            : null;

        $sourceId = $params['source_id'] ?? null;
        $keyword = $params['keyword'] ?? null;

        return view('veilles.articles.show', [
            'veille' => $veille,
            'article' => $article,
            'body' => $body,
            'originalUrl' => $originalUrl,
            'prev' => $this->articles->previous($veille, $article, $sourceId, $keyword),
            'next' => $this->articles->next($veille, $article, $sourceId, $keyword),
            'params' => $params,
        ]);
    }

    /**
     * Liste blanche stricte validée avant conversion : seuls
     * `source_id`, `keyword`, `page` transitent dans les liens
     * prev/next/retour/pagination. Tableaux, objets et valeurs
     * malformées ignorés proprement — jamais de cast hasardeux ni de
     * 500 ; `array_filter` avec callback `!== null` conserve `"0"`.
     *
     * @return array{source_id?: int, keyword?: string, page?: int}
     */
    private static function navParams(Request $request): array
    {
        $raw = $request->query();

        /** Valeur entière positive, ou null — sans cast hasardeux. */
        $positiveInt = static fn ($v): ?int => is_scalar($v)
            && filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
                ? (int) $v
                : null;

        return array_filter([
            'source_id' => $positiveInt($raw['source_id'] ?? null),
            'keyword' => is_string($raw['keyword'] ?? null)
                && ($k = trim($raw['keyword'])) !== ''
                    ? mb_substr($k, 0, 255)
                    : null,
            'page' => $positiveInt($raw['page'] ?? null),
        ], static fn ($v) => $v !== null);
    }
}
