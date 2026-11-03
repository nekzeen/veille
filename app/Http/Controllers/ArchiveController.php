<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Services\Articles\ArticleArchiveState;
use App\Services\Articles\ArticlePersonalAccess;
use App\Services\Articles\ArticleReadState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * « Mes archives » : articles masqués du flux normal
 * (DataModel §4.E — l'archive masque, ne protège pas).
 * Accès autonome via `ArticlePersonalAccess::hasAccess` (§4.D-bis) → 404 sinon.
 */
class ArchiveController extends Controller
{
    public function __construct(
        private readonly ArticleArchiveState $archives,
        private readonly ArticlePersonalAccess $personalAccess,
        private readonly ArticleReadState $readStates,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $paginator = $this->archives->archived($user);

        return view('archives.index', [
            'articles' => $paginator,
            'readMap' => $this->readStates->readMap(
                $user, $paginator->getCollection()->pluck('id')),
        ]);
    }

    public function archive(Request $request, Article $article): RedirectResponse
    {
        return $this->updateArchive($request, $article, archived: true);
    }

    public function unarchive(Request $request, Article $article): RedirectResponse
    {
        return $this->updateArchive($request, $article, archived: false);
    }

    private function updateArchive(
        Request $request, Article $article, bool $archived,
    ): RedirectResponse {
        $this->personalAccess->hasAccess($request->user(), $article)
            or abort(404);

        $archived
            ? $this->archives->archive($request->user(), $article)
            : $this->archives->unarchive($request->user(), $article);

        return $request->input('return') === 'article'
            ? redirect()->route('articles.show', $article)
            : redirect()->route('archives.index');
    }
}
