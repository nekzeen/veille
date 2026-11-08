<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lien de partage d'une veille (scope=single) ou de toutes les veilles
 * (scope=all) — M10_Conception_Partage §2/§3.
 *
 * Un seul jeton valide à la fois : la rotation écrase `token_hash`
 * et consigne `rotated_at` ; la révocation pose `revoked_at` +
 * `is_active=false`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $veille_id
 * @property string $scope
 * @property string $token_hash
 * @property string|null $label
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $rotated_at
 */
class VeilleShare extends Model
{
    protected $fillable = [
        'user_id', 'veille_id', 'scope', 'token_hash', 'label',
        'is_active', 'expires_at', 'revoked_at', 'rotated_at',
    ];

    public const SCOPE_SINGLE = 'single';

    public const SCOPE_ALL = 'all';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Veille, $this> */
    public function veille(): BelongsTo
    {
        return $this->belongsTo(Veille::class);
    }

    /** Partage utilisable : actif, non révoqué, non expiré. */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->whereNull('revoked_at')
            ->where(fn ($w) => $w->whereNull('expires_at')
                ->orWhere('expires_at', '>', now('UTC')));
    }

    /** @return Builder<VeilleShare> */
    public function scopeExpired(Builder $q): Builder
    {
        return $q->whereNotNull('expires_at')->where('expires_at', '<=', now('UTC'));
    }

    /** @return Builder<VeilleShare> */
    public function scopeRevoked(Builder $q): Builder
    {
        return $q->whereNotNull('revoked_at');
    }

    /** @return Builder<VeilleShare> */
    public function scopeSingle(Builder $q): Builder
    {
        return $q->where('scope', self::SCOPE_SINGLE);
    }

    /** @return Builder<VeilleShare> */
    public function scopeGlobal(Builder $q): Builder
    {
        return $q->where('scope', self::SCOPE_ALL);
    }

    public function isGlobal(): bool
    {
        return $this->scope === self::SCOPE_ALL;
    }
}
