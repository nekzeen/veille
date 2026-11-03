<?php

namespace App\Console\Commands;

use App\Jobs\SendVeilleDigestJob;
use App\Models\VeilleAlert;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reprise de l'outbox (DataModel §3.11), toutes les 10 min :
 *
 * 1. Clôture les lignes bloquées : `pending`/`processing` ayant
 *    épuisé leurs 3 tentatives ET dont le bail est absent ou expiré
 *    (une 3ᵉ tentative en vol n'est jamais tuée) → `failed`.
 * 2. Redispatche les réservables : tout `pending`, et tout
 *    `processing` à bail expiré avec tentatives restantes — le Job
 *    effectue lui-même la transition atomique, donc aucune
 *    réservation valide n'est jamais reprise et les sweeps
 *    concurrents ne peuvent pas produire de doublon d'envoi.
 */
class AlertsSweepCommand extends Command
{
    protected $signature = 'alerts:sweep';

    protected $description = 'Reprend les alertes bloquées de l’outbox (baux expirés, tentatives épuisées).';

    public function handle(): int
    {
        $expiry = now('UTC')->subMinutes(SendVeilleDigestJob::LEASE_MINUTES);

        $failed = VeilleAlert::whereIn('status', [VeilleAlert::STATUS_PENDING, VeilleAlert::STATUS_PROCESSING])
            ->where('attempts', '>=', SendVeilleDigestJob::MAX_ATTEMPTS)
            ->where(fn ($q) => $q->whereNull('reserved_at')->orWhere('reserved_at', '<', $expiry))
            ->update([
                'status' => VeilleAlert::STATUS_FAILED,
                'error' => 'Nombre maximal de tentatives atteint.',
                'reserved_at' => null,
                'reservation_token' => null,
            ]);

        $ids = VeilleAlert::where('attempts', '<', SendVeilleDigestJob::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q
                ->where('status', VeilleAlert::STATUS_PENDING)
                ->orWhere(fn (Builder $q2) => $q2
                    ->where('status', VeilleAlert::STATUS_PROCESSING)
                    ->where('reserved_at', '<', $expiry)))
            ->orderBy('id')->limit(100)->pluck('id');

        $ids->each(fn (int $id) => SendVeilleDigestJob::dispatch($id));

        $this->info("{$ids->count()} alerte(s) redispatchée(s), {$failed} passée(s) en échec.");

        return self::SUCCESS;
    }
}
