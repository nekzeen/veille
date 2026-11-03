<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Services\Articles\ArticleCategoryState;
use App\Services\Articles\ArticlePersonalAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * « Mes catégories » : gestion du classement personnel (CRUD) et
 * navigation par catégorie. Les contrôleurs restent minces — la
 * logique vit dans `ArticleCategoryState`.
 */
class CategoryController extends Controller
{
    public function __construct(
        private readonly ArticleCategoryState $categories,
        private readonly ArticlePersonalAccess $personalAccess,
    ) {}

    /** Catégorie de l'utilisateur courant ou 404 (isolation §4.D-bis). */
    private function own(Request $request, Category $category): Category
    {
        abort_unless($category->user_id === $request->user()->id, 404);

        return $category;
    }

    public function index(Request $request): View
    {
        return view('categories.index', [
            'categories' => $this->categories->categories($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->categories->create($request->user(), (string) $request->input('name', ''));

        return redirect()->route('categories.index');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->categories->rename(
            $this->own($request, $category), (string) $request->input('name', ''));

        return redirect()->route('categories.index');
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->categories->delete($this->own($request, $category));

        return redirect()->route('categories.index');
    }

    /** Articles non archivés de la catégorie — pagination + tri §4.E. */
    public function articles(Request $request, Category $category): View
    {
        $user = $request->user();

        return view('categories.articles', [
            'category' => $this->own($request, $category),
            'articles' => $this->categories->articlesInCategory($user, $category),
        ]);
    }

    /** Affectation — PUT. Catégorie du user + accès article requis. */
    public function setCategory(Request $request, Article $article): RedirectResponse
    {
        return $this->updateCategory($request, $article,
            fn ($user, $a) => $this->categories->setCategory(
                $user, $a, $this->ownCategory($request)));
    }

    /** Retrait — DELETE. Autres données personnelles préservées. */
    public function clearCategory(Request $request, Article $article): RedirectResponse
    {
        return $this->updateCategory($request, $article,
            fn ($user, $a) => $this->categories->clearCategory($user, $a));
    }

    /** Catégorie demandée appartenant à l'utilisateur, ou 404. */
    private function ownCategory(Request $request): Category
    {
        $id = $request->input('category_id');

        return Category::where('user_id', $request->user()->id)
            ->where('id', is_scalar($id) ? (int) $id : 0)
            ->firstOrFail();
    }

    private function updateCategory(
        Request $request, Article $article, \Closure $mutation,
    ): RedirectResponse {
        $this->personalAccess->hasAccess($request->user(), $article)
            or abort(404);

        $mutation($request->user(), $article);

        return $request->input('return') === 'article'
            ? redirect()->route('articles.show', $article)
            : redirect()->route('categories.index');
    }
}
