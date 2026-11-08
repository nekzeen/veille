<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Veille;
use App\Services\Articles\ArticleArchiveState;
use App\Services\Articles\ArticleCategoryState;
use App\Services\Articles\ArticleFavoriteState;
use App\Services\Articles\ArticleNoteState;
use App\Services\Articles\ArticleReadState;
use App\Services\Articles\HtmlContentSanitizer;
use App\Services\Articles\VeilleArticleQuery;
use App\Services\ArticleVeilleWriter;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
        private readonly ArticleReadState $readStates,
        private readonly ArticleFavoriteState $favoriteStates,
        private readonly ArticleNoteState $noteStates,
        private readonly ArticleArchiveState $archiveStates,
        private readonly ArticleCategoryState $categoryStates,
        private readonly ArticleVeilleWriter $veilleWriter,
    ) {}

    public function index(Request $request, Veille $veille): View
    {
        Gate::authorize('view', $veille);

        $params = self::navParams($request);

        $paginator = $this->articles
            ->paginate($veille, $params['source_id'] ?? null, $params['keyword'] ?? null, $params['state'])
            // `page` n'est jamais appensé : il figerait tous les liens.
            ->appends(array_diff_key($params, ['page' => true]));

        return view('veilles.articles.index', [
            'veille' => $veille,
            'articles' => $paginator,
            'filterSources' => $this->articles->filterSources($veille),
            'keywords' => $this->articles->matchedKeywords($veille),
            'params' => $params,
            'readMap' => $this->readStates->readMap(
                $request->user(), $paginator->getCollection()->pluck('id')),
            'favMap' => $this->favoriteStates->favoriteMap(
                $request->user(), $paginator->getCollection()->pluck('id')),
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
            'isRead' => $this->readStates->isRead($request->user(), $article),
            'isFavorite' => $this->favoriteStates->isFavorite($request->user(), $article),
            'note' => $this->noteStates->getNote($request->user(), $article),
            'categories' => $this->categoryStates->categories($request->user()),
            'categoryId' => $this->categoryStates->categoryIdOf(
                $request->user(), $article),
            'prev' => $this->articles->previous($veille, $article, $sourceId, $keyword),
            'next' => $this->articles->next($veille, $article, $sourceId, $keyword),
            'params' => $params,
        ]);
    }

    /** Marquer lu — PUT. Idempotent (PK composite). */
    public function markRead(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->readStates->markRead($user, $a));
    }

    /**
     * « Tout marquer lu » — POST (M11.4). Périmètre : tous les non lus
     * de la veille (rétention 6 mois + exclusions M9) ; statut global
     * (user_id, article_id). Retour vers l'onglet Non lus.
     */
    public function markAllRead(Request $request, Veille $veille): RedirectResponse
    {
        Gate::authorize('view', $veille);

        $count = $this->readStates->markAllRead($request->user(), $veille);

        return redirect()
            ->route('veilles.articles.index', [$veille, 'state' => 'unread'])
            ->with('status', $count.' article(s) marqué(s) comme lu(s) — changement global pour votre compte.');
    }

    /**
     * M12.21 — retire l'article de CETTE veille uniquement : suppression
     * de la seule association article_veille via ArticleVeilleWriter
     * (verrou + last_detached_at atomique). L'article partagé et les
     * états personnels (favori, note, archive, lecture) sont conservés.
     */
    public function detach(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        Gate::authorize('view', $veille);

        $this->veilleWriter->deleteFor($veille, [$article->id]);

        return back()->with('status', 'Article retiré de cette veille.');
    }

    /** Marquer non lu — DELETE. Ligne supprimée si rien d'autre à conserver. */
    public function markUnread(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->readStates->markUnread($user, $a));
    }

    /** Marquer favori — PUT. Idempotent. */
    public function markFavorite(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->favoriteStates->markFavorite($user, $a));
    }

    /** Retirer le favori — DELETE. Autres données personnelles préservées. */
    public function removeFavorite(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->favoriteStates->removeFavorite($user, $a));
    }

    /** Enregistrer la note — PUT. Vide = suppression (règle §3.9). */
    public function saveNote(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->noteStates->saveNote(
                $user, $a, is_string($n = $request->input('note')) ? $n : null));
    }

    /** Supprimer la note — DELETE. Autres données personnelles préservées. */
    public function deleteNote(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->noteStates->deleteNote($user, $a));
    }

    /** Archiver — PUT. L'article sort ensuite du flux veille (masquage §4.E). */
    public function archiveArticle(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->archiveStates->archive($user, $a));
    }

    /** Désarchiver — DELETE. Autres données personnelles préservées. */
    public function unarchiveArticle(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->archiveStates->unarchive($user, $a));
    }

    /** Affecter une catégorie du user — PUT. Étrangère → 404 (§4.D-bis). */
    public function setCategory(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->categoryStates->setCategory(
                $user, $a, $this->ownCategory($request, $user->id)));
    }

    /** Retirer la catégorie — DELETE. Autres états préservés. */
    public function clearCategory(Request $request, Veille $veille, Article $article): RedirectResponse
    {
        return $this->mutateArticle($request, $veille, $article,
            fn ($user, $a) => $this->categoryStates->clearCategory($user, $a));
    }

    /** Catégorie demandée appartenant à l'utilisateur, ou 404. */
    private function ownCategory(Request $request, int $userId): Category
    {
        $id = $request->input('category_id');

        return Category::where('user_id', $userId)
            ->where('id', is_scalar($id) ? (int) $id : 0)
            ->firstOrFail();
    }

    /**
     * Mutation d'état personnel : mêmes garde-fous que la lecture —
     * propriété de la veille (policy) + association article/veille
     * dans la fenêtre (404 sinon). `user_id` = utilisateur
     * authentifié, jamais un paramètre.
     */
    private function mutateArticle(
        Request $request, Veille $veille, Article $article, Closure $mutation,
    ): RedirectResponse {
        Gate::authorize('view', $veille);

        $article = $this->articles->findArticle($veille, $article->id)
            ?? abort(404);

        $params = self::navParams($request);
        $mutation($request->user(), $article);

        // `return` limité à la liste blanche — jamais d'URL arbitraire.
        if ($request->input('return') === 'show') {
            return redirect()->route(
                'veilles.articles.show', [$veille, $article] + $params);
        }

        return redirect()->route(
            'veilles.articles.index', [$veille] + $params);
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
            'state' => in_array($raw['state'] ?? null, ['unread', 'all', 'read'], true)
                ? $raw['state']
                : 'unread',
        ], static fn ($v) => $v !== null);
    }
}
