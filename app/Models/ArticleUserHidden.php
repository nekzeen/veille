<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Justification de masquage d'un article pour un utilisateur
 * (M9 §9.1). `exclusion_id` NULL = masquage manuel futur.
 */
class ArticleUserHidden extends Model
{
    protected $table = 'article_user_hidden';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'article_id', 'exclusion_id', 'created_at'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<UserExclusion, $this> */
    public function exclusion(): BelongsTo
    {
        return $this->belongsTo(UserExclusion::class, 'exclusion_id');
    }
}
