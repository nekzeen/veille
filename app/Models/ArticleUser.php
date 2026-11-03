<?php

namespace App\Models;

use Database\Factories\ArticleUserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * État personnel d'un article par utilisateur (DataModel §3.9).
 *
 * PK composite `(user_id, article_id)` — pas d'auto-incrément.
 * Sémantique de lecture : absence de ligne OU `is_read=0` = non lu.
 * `is_read`/`is_archived` seuls ne protègent pas de la purge (§4.E) ;
 * `is_favorite`, `note`, `category_id` si.
 */
class ArticleUser extends Model
{
    /** @use HasFactory<ArticleUserFactory> */
    use HasFactory;

    protected $table = 'article_user';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'article_id',
        'category_id',
        'is_read',
        'is_favorite',
        'is_archived',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'is_favorite' => 'boolean',
            'is_archived' => 'boolean',
        ];
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
            ->where('user_id', '=', $this->getAttribute('user_id'))
            ->where('article_id', '=', $this->getAttribute('article_id'));
    }

    /**
     * La ligne porte-t-elle une donnée personnelle autre que les
     * colonnes exclues ? Réponse « non » ⇒ `delete()` est sûr
     * (§3.9 : pas de ligne vide conservée).
     *
     * @param  list<string>  $excluding  clés d'état à ignorer
     */
    public function hasPersonalDataBesides(array $excluding): bool
    {
        $flags = [
            'is_read' => $this->is_read,
            'is_favorite' => $this->is_favorite,
            'is_archived' => $this->is_archived,
            'note' => $this->note !== null,
            'category_id' => $this->category_id !== null,
        ];

        return in_array(true, array_diff_key($flags, array_flip($excluding)), true);
    }

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
}
