<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\Category;
use App\Models\User;
use App\Services\Exclusions\ArticleVisibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Catégories personnelles de classement (DataModel §3.9/§3.10,
 * §4.D-bis, §4.E, §4.F).
 *
 * La FK composite `(user_id, category_id) → categories(user_id, id)`
 * rend techniquement impossible le classement d'un article dans une
 * catégorie d'un autre utilisateur — l'applicatif vérifie en amont
 * pour retourner un 404 propre plutôt qu'une violation d'intégrité.
 */
final class ArticleCategoryState
{
    public function __construct(
        private readonly ArticlePersonalAccess $personalAccess,
    ) {}

    /**
     * Catégories de l'utilisateur, triées par nom, avec
     * `articles_count` limité aux articles non archivés
     * (masquage §4.E — cohérent avec la liste de M1.14.2).
     *
     * @return Collection<int, Category>
     */
    public function categories(User $user): Collection
    {
        return Category::where('user_id', $user->id)
            ->withCount(['articleUsers as articles_count' => fn ($q) => $q
                ->where('is_archived', false)
                ->where(fn ($w) => ArticleVisibility::apply($w, $user->id, 'article_user.article_id'))])
            ->orderBy('name')
            ->get();
    }

    /**
     * Créer : trim, obligatoire, ≤ 100, unique par utilisateur.
     * La contrainte DB reste le garde-fou en cas de concurrence.
     */
    public function create(User $user, string $name): Category
    {
        $name = trim($name);
        $this->validateName($name, $user);

        try {
            return Category::create(['user_id' => $user->id, 'name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    /** Renommer : mêmes règles, sauf soi-même — jamais de fusion. */
    public function rename(Category $category, string $name): Category
    {
        $name = trim($name);
        $this->validateName($name, $category->user()->firstOrFail(), $category);

        try {
            $category->name = $name;
            $category->save();

            return $category;
        } catch (UniqueConstraintViolationException) {
            throw $this->nameTaken();
        }
    }

    /**
     * Suppression §4.F en une transaction : `category_id` → NULL sur
     * les lignes du propriétaire, élagage des lignes devenues vides
     * (invariant « pas de ligne vide » §3.9), puis `DELETE`.
     * `ON DELETE RESTRICT` demeure le garde-fou.
     */
    public function delete(Category $category): void
    {
        DB::transaction(function () use ($category) {
            ArticleUser::where('user_id', $category->user_id)
                ->where('category_id', $category->id)
                ->update(['category_id' => null]);

            ArticleUser::where('user_id', $category->user_id)
                ->whereNull('category_id')
                ->where('is_read', false)
                ->where('is_favorite', false)
                ->where('is_archived', false)
                ->whereNull('note')
                ->delete();

            $category->delete();
        });
    }

    /**
     * Affecter une catégorie : la catégorie doit appartenir à
     * l'utilisateur ET l'article être accessible (§4.D-bis) —
     * `ModelNotFoundException` pour ne rien révéler.
     * `updateOrCreate` préserve les autres états personnels.
     */
    public function setCategory(User $user, Article $article, Category $category): void
    {
        if ($category->user_id !== $user->id
            || ! $this->personalAccess->hasAccess($user, $article)) {
            throw (new ModelNotFoundException)->setModel(Category::class);
        }

        ArticleUser::updateOrCreate(
            ['user_id' => $user->id, 'article_id' => $article->id],
            ['category_id' => $category->id],
        );
    }

    /**
     * Retirer la catégorie : `category_id` → NULL en préservant les
     * autres données ; ligne supprimée si elle ne porte plus rien
     * (règle centralisée `hasPersonalDataBesides` §3.9).
     */
    public function clearCategory(User $user, Article $article): void
    {
        $row = ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->first();

        if ($row === null) {
            return;
        }

        if (! $row->hasPersonalDataBesides(['category_id'])) {
            $row->delete();

            return;
        }

        $row->category_id = null;
        $row->save();
    }

    /**
     * `category_id` courant de l'article pour l'utilisateur (fiche).
     */
    public function categoryIdOf(User $user, Article $article): ?int
    {
        $id = ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)
            ->value('category_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Articles d'une catégorie : archivés masqués (§4.E), tri date
     * effective DESC puis `id` DESC, 20/page — même règle que les
     * favoris.
     */
    public function articlesInCategory(
        User $user, Category $category,
    ): LengthAwarePaginator {
        return Article::query()
            ->join('article_user', fn ($join) => $join
                ->on('article_user.article_id', '=', 'articles.id')
                ->where('article_user.user_id', $user->id)
                ->where('article_user.category_id', $category->id)
                ->where('article_user.is_archived', false))
            ->where(fn ($q) => ArticleVisibility::apply($q, $user->id, 'articles.id'))
            ->with('source')
            ->select('articles.*')
            ->orderByRaw('COALESCE(articles.published_at, articles.collected_at) DESC')
            ->orderByDesc('articles.id')
            ->paginate(20);
    }

    private function validateName(
        string $name, User $user, ?Category $ignore = null,
    ): void {
        Validator::make(['name' => $name], [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'name')
                    ->where('user_id', $user->id)
                    ->ignore($ignore?->id),
            ],
        ])->validate();
    }

    private function nameTaken(): ValidationException
    {
        return ValidationException::withMessages(
            ['name' => 'Ce nom de catégorie est déjà utilisé.']);
    }
}
