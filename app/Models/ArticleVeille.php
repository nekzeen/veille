<?php

namespace App\Models;

use Database\Factories\ArticleVeilleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleVeille extends Model
{
    /** @use HasFactory<ArticleVeilleFactory> */
    use HasFactory;

    protected $table = 'article_veille';

    /** PK composite (veille_id, article_id) — pas d'auto-incrément. */
    public $incrementing = false;

    /** Pas d'updated_at ; created_at est historique (§3.8). */
    public const UPDATED_AT = null;

    /** timestamp(6) : comparée aux bornes microseconde des digests. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'veille_id',
        'article_id',
        'user_id',
        'matched_keyword',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Même règle que VeilleKeyword : user_id dérivé de la veille
        // si absent, la FK composite rejette toute incohérence.
        static::creating(function (ArticleVeille $match): void {
            $match->user_id ??= $match->veille?->user_id;
        });
    }

    /**
     * La clé primaire est composite : les écritures ciblent les deux
     * membres, pas la colonne `id` par défaut.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query
            ->where('veille_id', '=', $this->getAttribute('veille_id'))
            ->where('article_id', '=', $this->getAttribute('article_id'));
    }

    /** @return BelongsTo<Veille, $this> */
    public function veille(): BelongsTo
    {
        return $this->belongsTo(Veille::class);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
