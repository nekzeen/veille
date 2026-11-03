<?php

namespace App\Models;

use Database\Factories\VeilleAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alerte quotidienne d'une veille (DataModel §3.11) : outbox à cycle
 * pending → processing → sent/failed, fenêtre de correspondances
 * délimitée par les curseurs [start_match_id, end_match_id] sur
 * article_veille.id ; period_start/period_end restent des champs
 * d'affichage (instants UTC).
 *
 * @property int $id
 * @property int $veille_id
 * @property int $user_id
 * @property Carbon $digest_date
 * @property string $status
 * @property Carbon|null $period_start
 * @property int|null $start_match_id
 * @property Carbon|null $period_end
 * @property int|null $end_match_id
 * @property int $articles_count
 * @property int $attempts
 * @property Carbon|null $reserved_at
 * @property string|null $reservation_token
 * @property string|null $error
 * @property Carbon|null $sent_at
 */
class VeilleAlert extends Model
{
    /** @use HasFactory<VeilleAlertFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** timestamp(6) — cohérent avec les autres modèles. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'veille_id',
        'user_id',
        'digest_date',
        'status',
        'period_start',
        'start_match_id',
        'period_end',
        'end_match_id',
        'articles_count',
        'attempts',
        'reserved_at',
        'reservation_token',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'digest_date' => 'date',
            'start_match_id' => 'integer',
            'end_match_id' => 'integer',
            'articles_count' => 'integer',
            'attempts' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'reserved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
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
