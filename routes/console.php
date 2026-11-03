<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Collecte RSS/Atom (Orchestration §8) : sélection des sources dues
// toutes les 5 min ; drainage de la queue database chaque minute, le
// worker s'arrête quand la file est vide et au plus tard après 50 s.
// `withoutOverlapping` pose un verrou dans cache_locks (store database) ;
// le TTL borné remplace le défaut de 1440 min : un processus tué ne peut
// geler la tâche que 15 min (feeds:fetch, cadence D12) ou 10 min (worker).
Schedule::command('feeds:fetch')->everyFiveMinutes()->withoutOverlapping(15);
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(10);

// Digests (M5) : génération quotidienne à 06:30 heure de Paris
// (l'app tourne en UTC — le fuseau fonctionnel est explicite) ;
// distribution des pending et reprise de l'outbox — sansOverlapping
// + réservation atomique des Jobs : pas de double envoi.
Schedule::command('alerts:generate')->dailyAt('06:30')->timezone('Europe/Paris')->withoutOverlapping(15);
Schedule::command('alerts:distribute')->everyFiveMinutes()->withoutOverlapping(5);
Schedule::command('alerts:sweep')->everyTenMinutes()->withoutOverlapping(10);
