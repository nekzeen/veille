<?php

namespace App\Console\Commands;

use App\Jobs\SendVeilleDigestJob;
use App\Models\VeilleAlert;
use Illuminate\Console\Command;

/**
 * Distribution des digests : place les alertes `pending` en file
 * database (drainée chaque minute par `queue:work --stop-when-empty`).
 * La commande n'envoie rien elle-même — l'idempotence est garantie
 * par la réservation atomique du Job : des exécutions simultanées
 * ne produisent pas plusieurs envois pour une même alerte.
 */
class AlertsDistributeCommand extends Command
{
    protected $signature = 'alerts:distribute';

    protected $description = 'Met en file les alertes en attente d’envoi.';

    public function handle(): int
    {
        $dispatched = 0;

        VeilleAlert::where('status', VeilleAlert::STATUS_PENDING)
            ->where('attempts', '<', SendVeilleDigestJob::MAX_ATTEMPTS)
            ->orderBy('id')->limit(200)->pluck('id')
            ->each(function (int $alertId) use (&$dispatched): void {
                SendVeilleDigestJob::dispatch($alertId);
                $dispatched++;
            });

        $this->info("{$dispatched} alerte(s) mise(s) en file.");

        return self::SUCCESS;
    }
}
