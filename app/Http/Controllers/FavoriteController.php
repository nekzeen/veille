<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Services\Articles\ArticleArchiveState;
use App\Services\Articles\ArticleFavoriteState;
use App\Services\Articles\ArticleNoteState;
use App\Services\Articles\ArticlePersonalAccess;
use App\Services\Articles\ArticleReadState;
use App\Services\Articles\HtmlContentSanitizer;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Espace personnel « Mes favoris » et lecture autonome d'un article
 * (DataModel §4.D-bis) : l'accès tient à une correspondance avec
 * l'une de ses veilles OU à sa propre ligne `article_user` — le favori
 * survit donc à la suppression de la correspondance.
 */
class FavoriteController extends Controller
{
    public function __construct(
        private readonly ArticleFavoriteState $favorites,
        private readonly ArticleReadState $readStates,
        private readonly ArticleNoteState $noteStates,
        private readonly ArticleArchiveState $archiveStates,
        private readonly ArticlePersonalAccess $personalAccess,
        private readonly HtmlContentSanitizer $sanitizer,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $paginator = $this->favorites->favorites($user);

        return view('favorites.index', [
            'articles' => $paginator,
            'readMap' => $this->readStates->readMap(
                $user, $paginator->getCollection()->pluck('id')),
        ]);
    }

    /** Lecture autonome : même pipeline sanitizer que la lecture veille. */
    public function show(Request $request, Article $article): View
    {
        $user = $request->user();
        $this->personalAccess->hasAccess($user, $article) or abort(404);

        $article->loadMissing('source');

        $body = $this->sanitizer->sanitize($article->content);
        if ($body === '') {
            $body = $this->sanitizer->sanitize($article->summary);
        }

        $scheme = strtolower((string) parse_url($article->url, PHP_URL_SCHEME));

        return view('favorites.show', [
            'article' => $article,
            'body' => $body,
            'originalUrl' => ($scheme === 'http' || $scheme === 'https')
                ? $article->url : null,
            'isRead' => $this->readStates->isRead($user, $article),
            'isFavorite' => $this->favorites->isFavorite($user, $article),
            'isArchived' => $this->archiveStates->isArchived($user, $article),
            'note' => $this->noteStates->getNote($user, $article),
        ]);
    }

    /** Enregistrer la note — PUT. Vide = suppression (règle §3.9). */
    public function saveNote(Request $request, Article $article): RedirectResponse
    {
        return $this->updateNote($request, $article,
            fn ($user, $a) => $this->noteStates->saveNote(
                $user, $a, is_string($n = $request->input('note')) ? $n : null));
    }

    /** Supprimer la note — DELETE. Autres données personnelles préservées. */
    public function deleteNote(Request $request, Article $article): RedirectResponse
    {
        return $this->updateNote($request, $article,
            fn ($user, $a) => $this->noteStates->deleteNote($user, $a));
    }

    /** Mutation de note : accès personnel §4.D-bis exigé → 404 sinon. */
    private function updateNote(
        Request $request, Article $article, Closure $mutation,
    ): RedirectResponse {
        $this->personalAccess->hasAccess($request->user(), $article)
            or abort(404);

        $mutation($request->user(), $article);

        return redirect()->route('articles.show', $article);
    }

    public function markFavorite(Request $request, Article $article): RedirectResponse
    {
        return $this->updateFavorite($request, $article, favorite: true);
    }

    public function removeFavorite(Request $request, Article $article): RedirectResponse
    {
        return $this->updateFavorite($request, $article, favorite: false);
    }

    private function updateFavorite(
        Request $request, Article $article, bool $favorite,
    ): RedirectResponse {
        $this->personalAccess->hasAccess($request->user(), $article)
            or abort(404);

        $favorite
            ? $this->favorites->markFavorite($request->user(), $article)
            : $this->favorites->removeFavorite($request->user(), $article);

        // `return` limité à la liste blanche — jamais d'URL arbitraire.
        return $request->input('return') === 'article'
            ? redirect()->route('articles.show', $article)
            : redirect()->route('favorites.index');
    }
}
