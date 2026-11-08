<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $last_fetched_at
 * @property Carbon|null $last_success_at
 * @property int $fail_count
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $detection
 */
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /** Rythme normal de collecte, en minutes (D12). */
    public const FETCH_INTERVAL_MINUTES = 15;

    /** Plafond du backoff, en minutes (P6). */
    public const BACKOFF_MAX_MINUTES = 360;

    /** Échecs consécutifs entraînant la désactivation (P2). */
    public const DEACTIVATION_THRESHOLD = 10;

    protected $fillable = [
        'feed_url',
        'submitted_url',
        'fetch_url',
        'connector',
        'config',
        'config_state',
        'detection',
        'name',
        'site_url',
        'description',
        'theme_id',
        'is_catalogue',
        'is_active',
        'orphaned_at',
        'catalogue_inactive_at',
    ];

    protected function casts(): array
    {
        return [
            'theme_id' => 'integer',
            'is_catalogue' => 'boolean',
            'is_active' => 'boolean',
            'orphaned_at' => 'datetime',
            'catalogue_inactive_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'last_success_at' => 'datetime',
            'fail_count' => 'integer',
            'config' => 'array',
            'detection' => 'array',
        ];
    }

    /**
     * Délai avant nouvelle tentative (P6) : min(15 × 2^(n−1), 360) min,
     * `n` = échecs consécutifs ; 0 ou 1 → rythme normal (D12).
     */
    public static function backoffMinutes(int $failCount): int
    {
        $exponent = min(max($failCount - 1, 0), 5);

        return min(self::FETCH_INTERVAL_MINUTES * (2 ** $exponent), self::BACKOFF_MAX_MINUTES);
    }

    /** Source collectable : active et non orpheline (P1, P2). */
    public function isEligibleForFetch(): bool
    {
        return $this->is_active && $this->orphaned_at === null;
    }

    /**
     * Échéance implicite `last_fetched_at + delay(fail_count)` ;
     * null si jamais collectée (due immédiatement).
     */
    public function dueAt(): ?CarbonInterface
    {
        return $this->last_fetched_at?->copy()->addMinutes(self::backoffMinutes($this->fail_count ?? 0));
    }

    public function isDue(?CarbonInterface $now = null): bool
    {
        $dueAt = $this->dueAt();

        return $dueAt === null || $dueAt->lessThanOrEqualTo($now ?? now());
    }

    /** @return HasMany<UserSource, $this> */
    public function userSources(): HasMany
    {
        return $this->hasMany(UserSource::class);
    }

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /** @return BelongsToMany<Veille, $this> */
    public function veilles(): BelongsToMany
    {
        return $this->belongsToMany(Veille::class, 'source_veille')
            ->withPivot('user_id');
    }

    /** @return BelongsTo<SourceTheme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(SourceTheme::class, 'theme_id');
    }
}
