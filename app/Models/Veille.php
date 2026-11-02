<?php

namespace App\Models;

use Database\Factories\VeilleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Veille extends Model
{
    /** @use HasFactory<VeilleFactory> */
    use HasFactory;

    /** timestamp(6) : created_at est la borne initiale des digests (§3.4). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'is_active',
        'alerts_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'alerts_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<VeilleKeyword, $this> */
    public function keywords(): HasMany
    {
        return $this->hasMany(VeilleKeyword::class);
    }

    /**
     * `user_id` du pivot est injecté depuis la veille à l'attach
     * (tenant dénormalisé) ; la FK composite reste le garde-fou SQL.
     * Injection limitée aux veilles persistées : en contexte
     * `with`/`whereHas`, la relation reste utilisable non scopée.
     *
     * @return BelongsToMany<Source, $this>
     */
    public function sources(): BelongsToMany
    {
        $relation = $this->belongsToMany(Source::class, 'source_veille')
            ->withPivot('user_id');

        if ($this->exists) {
            $relation->withPivotValue('user_id', $this->user_id);
        }

        return $relation;
    }

    /**
     * Articles correspondants (matching persisté, §3.8). Même
     * injection de `user_id` que `sources()` ; `matched_keyword` et
     * `created_at` sont fournis explicitement à l'attach.
     *
     * @return BelongsToMany<Article, $this>
     */
    public function articles(): BelongsToMany
    {
        $relation = $this->belongsToMany(Article::class, 'article_veille')
            ->withPivot(['user_id', 'matched_keyword', 'created_at']);

        if ($this->exists) {
            $relation->withPivotValue('user_id', $this->user_id);
        }

        return $relation;
    }

    /** @return HasMany<ArticleVeille, $this> */
    public function articleVeilles(): HasMany
    {
        return $this->hasMany(ArticleVeille::class);
    }
}
