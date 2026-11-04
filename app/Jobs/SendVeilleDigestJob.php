<?php

namespace App\Jobs;

use App\Mail\VeilleDigestMail;
use App\Models\ArticleVeille;
use App\Models\VeilleAlert;
use App\Services\Exclusions\ArticleVisibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Envoi d'un digest (DataModel §3.11) : réservation atomique
 * `pending → processing` (bail 10 min, 3 tentatives max,
 * `reservation_token` UUID), envoi du mailable, clôture `sent`.
 * Toute écriture post-réservation est gardée par le token : un
 * worker dont le bail a expiré ne peut écraser une réservation plus
 * récente. Livraison au moins une fois : un crash entre l'envoi et
 * la clôture peut produire un doublon — assumé.
 */
class SendVeilleDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const LEASE_MINUTES = 10;

    public const MAX_ATTEMPTS = 3;

    /** Les tentatives sont gérées par l'outbox, pas par la queue. */
    public $tries = 1;

    public function __construct(
        public readonly int $alertId,
    ) {}

    public function handle(): void
    {
        $token = (string) Str::uuid();

        // Réservation atomique : pending OU processing à bail expiré,
        // tentatives restantes. affected=1 ⇒ seul détenteur du bail.
        $reserved = DB::table('veille_alerts')
            ->where('id', $this->alertId)
            ->whereIn('status', [VeilleAlert::STATUS_PENDING, VeilleAlert::STATUS_PROCESSING])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q->whereNull('reserved_at')
                ->orWhere('reserved_at', '<', now('UTC')->subMinutes(self::LEASE_MINUTES)))
            ->update([
                'status' => VeilleAlert::STATUS_PROCESSING,
                'reserved_at' => now('UTC'),
                'reservation_token' => $token,
                'attempts' => DB::raw('attempts + 1'),
                'error' => null,
            ]);

        if ($reserved !== 1) {
            return; // sent/failed, bail valide détenu ailleurs, ou +3.
        }

        $alert = VeilleAlert::with('veille', 'user')->find($this->alertId);
        if ($alert === null || $alert->reservation_token !== $token) {
            return; // Réservation reprise entre-temps — abandon.
        }

        try {
            $items = $this->digestItems($alert);

            // Fenêtre ∩ correspondances vivantes ; aucun e-mail vide.
            if ($items->isNotEmpty()) {
                Mail::to($alert->user->email)->send(new VeilleDigestMail($alert, $items));
            }

            // Clôture gardée par le token : affected=0 ⇒ bail perdu.
            DB::table('veille_alerts')->where('id', $alert->id)
                ->where('status', VeilleAlert::STATUS_PROCESSING)
                ->where('reservation_token', $token)
                ->update([
                    'status' => VeilleAlert::STATUS_SENT,
                    'sent_at' => now('UTC'),
                    'articles_count' => $items->count(),
                    'reserved_at' => null,
                    'reservation_token' => null,
                    'error' => null,
                ]);
        } catch (\Throwable $e) {
            // Retour pending (retry) ou failed (3ᵉ échec) — gardé par
            // le token : jamais d'écrasement d'une réservation récente.
            DB::table('veille_alerts')->where('id', $alert->id)
                ->where('status', VeilleAlert::STATUS_PROCESSING)
                ->where('reservation_token', $token)
                ->update([
                    'status' => $alert->attempts >= self::MAX_ATTEMPTS
                        ? VeilleAlert::STATUS_FAILED
                        : VeilleAlert::STATUS_PENDING,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                    'reserved_at' => null,
                    'reservation_token' => null,
                ]);
        }
    }

    /**
     * Correspondances de la fenêtre ]start,end] encore existantes,
     * plafonnées à 50 — les suppressions intervenues entre
     * génération et envoi disparaissent naturellement du digest.
     *
     * @return Collection<int, ArticleVeille>
     */
    private function digestItems(VeilleAlert $alert): Collection
    {
        return ArticleVeille::where('veille_id', $alert->veille_id)
            ->where('id', '>', $alert->start_match_id)
            ->where('id', '<=', $alert->end_match_id)
            ->where(fn ($q) => ArticleVisibility::apply($q, $alert->user_id, 'article_veille.article_id'))
            ->with('article.source')
            ->orderBy('id')
            ->limit(50)
            ->get();
    }
}
