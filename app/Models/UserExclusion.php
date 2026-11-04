<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Règle d'exclusion personnelle (M9_Conception_Exclusions §2) :
 * `kind` = 'url' | 'keyword' ; le sous-type URL (domaine, sous-domaine,
 * URL exacte, chemin) est déduit du motif — pas de colonne dédiée.
 */
class UserExclusion extends Model
{
    public const KIND_URL = 'url';

    public const KIND_KEYWORD = 'keyword';

    protected $fillable = ['user_id', 'kind', 'pattern', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sous-type d'une règle `url` : domain|subdomain|exact|path. */
    public function subtype(): ?string
    {
        if ($this->kind !== self::KIND_URL) {
            return null;
        }
        if (str_starts_with($this->pattern, '*.')) {
            return 'subdomain';
        }
        if (preg_match('#^https?://#i', $this->pattern) === 1) {
            return 'exact';
        }
        if (str_starts_with($this->pattern, '/')) {
            return 'path';
        }

        return 'domain';
    }

    /** Étiquette lisible du sous-type pour l'interface. */
    public function typeLabel(): string
    {
        if ($this->kind === self::KIND_KEYWORD) {
            return 'Expression';
        }

        return [
            'domain' => 'Domaine',
            'subdomain' => 'Sous-domaines',
            'exact' => 'URL exacte',
            'path' => 'Chemin',
        ][$this->subtype() ?? 'domain'];
    }
}
