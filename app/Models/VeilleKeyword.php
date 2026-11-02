<?php

namespace App\Models;

use Database\Factories\VeilleKeywordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VeilleKeyword extends Model
{
    /** @use HasFactory<VeilleKeywordFactory> */
    use HasFactory;

    protected $table = 'veille_keywords';

    protected $fillable = [
        'veille_id',
        'user_id',
        'term',
    ];

    protected static function booted(): void
    {
        // Forme stockée normalisée (§3.5) : trim + minuscules — la
        // contrainte UNIQUE(veille_id, term) s'applique sur cette forme.
        // Non chaîne (null…) : laissée telle quelle, le NOT NULL SQL
        // la rejettera — jamais convertie en '' ni coercée ici.
        static::saving(function (VeilleKeyword $keyword): void {
            $term = $keyword->getAttribute('term');
            if (is_string($term)) {
                $keyword->term = mb_strtolower(trim($term), 'UTF-8');
            }
        });

        // Tenant dénormalisé : dérivé de la veille si absent ; un
        // user_id incohérent explicitement fourni est rejeté par la
        // FK composite (veille_id, user_id) → veilles(id, user_id).
        static::creating(function (VeilleKeyword $keyword): void {
            $keyword->user_id ??= $keyword->veille?->user_id;
        });
    }

    /** @return BelongsTo<Veille, $this> */
    public function veille(): BelongsTo
    {
        return $this->belongsTo(Veille::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
