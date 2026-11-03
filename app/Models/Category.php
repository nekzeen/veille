<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catégorie personnelle de classement des articles (DataModel §3.10).
 *
 * `UNIQUE(user_id, name)` et `UNIQUE(user_id, id)` — cette dernière
 * est l'index parent de la FK composite
 * `article_user(user_id, category_id) → categories(user_id, id)`
 * **RESTRICT** : la suppression d'une catégorie exige la transaction
 * applicative §4.F (NULL-out puis DELETE), jamais un SET NULL
 * (mettrait `user_id` à NULL).
 *
 * @property int $articles_count compteur chargé par `withCount`
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Lignes `article_user` de cette catégorie. Le jointage sur
     * `category_id` suffit : `UNIQUE(user_id, id)` + FK composite
     * garantissent que seules des lignes du même utilisateur peuvent
     * référencer cette catégorie (tenant porté par la contrainte).
     *
     * @return HasMany<ArticleUser, $this>
     */
    public function articleUsers(): HasMany
    {
        return $this->hasMany(ArticleUser::class, 'category_id');
    }
}
