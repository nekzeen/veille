<?php

namespace App\Console\Commands;

use App\Jobs\FetchFeedJob;
use App\Models\Source;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FetchFeedsCommand extends Command
{
    protected $signature = 'feeds:fetch';

    protected $description = 'Sélectionne les sources arrivées à échéance et dispatche leur collecte.';

    /** Taille des lots de parcours de la table sources. */
    private const CHUNK_SIZE = 100;

    /**
     * Sélectionne les sources actives et non orphelines arrivées à
     * échéance puis dispatche un FetchFeedJob par source
     * (Orchestration §6). Aucune requête HTTP, aucun parsing, aucune
     * persistance d'articles : uniquement sélection et dispatch.
     */
    public function handle(): int
    {
        // Référence temporelle unique : l'échéance est évaluée à la même
        // heure pendant tout le cycle, même sur un parcours long.
        $now = now();
        $examined = $eligible = $dispatched = $alreadyQueued = 0;

        $this->info('Collecte des flux RSS/Atom');

        try {
            // L'index (is_active, last_fetched_at) porte le filtre SQL ;
            // l'échéance (backoff P6) est évaluée en PHP par les helpers
            // de Source — la règle n'est pas dupliquée ici.
            foreach (Source::query()
                ->where('is_active', true)
                ->where('catalogue_state', 'active')
                ->lazyById(self::CHUNK_SIZE) as $source) {
                $examined++;

                if (! $source->isEligibleForFetch() || ! $source->isDue($now)) {
                    continue;
                }

                $eligible++;

                if ($this->isAlreadyQueued($source)) {
                    $alreadyQueued++;

                    continue;
                }

                FetchFeedJob::dispatch($source->id);
                $dispatched++;
            }
        } catch (Throwable $e) {
            // Les jobs déjà dispatchés restent en file (queue durable) ;
            // le cycle est signalé comme incomplet, jamais comme succès.
            Log::error('feeds.fetch.cycle_failed', [
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 500),
                'examined' => $examined,
                'eligible' => $eligible,
                'dispatched' => $dispatched,
                'already_queued' => $alreadyQueued,
            ]);

            $this->error('Cycle interrompu : '.$e::class);
            $this->summary($examined, $eligible, $dispatched, $alreadyQueued);

            return self::FAILURE;
        }

        $this->summary($examined, $eligible, $dispatched, $alreadyQueued);
        $this->info('Terminé.');

        return self::SUCCESS;
    }

    /**
     * Le dispatch d'un job ShouldBeUnique déjà posé est ignoré par
     * Laravel sans résultat observable. La seule détection publique est
     * de tenter le verrou unique P7 : s'il est déjà tenu, un job est en
     * file ou en exécution pour cette source. S'il est libre, il est
     * relâché immédiatement et le dispatch réel le reprend de façon
     * atomique ; une course résiduelle peut alors compter un dispatch
     * tenté mais ignoré (limite assumée, compteur « dispatchés » =
     * tentatives).
     */
    private function isAlreadyQueued(Source $source): bool
    {
        $job = new FetchFeedJob($source->id);
        $lock = Cache::lock(UniqueLock::getKey($job), $job->uniqueFor);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    private function summary(int $examined, int $eligible, int $dispatched, int $alreadyQueued): void
    {
        $this->line('Sources examinées : '.$examined);
        $this->line('Sources éligibles : '.$eligible);
        $this->line('Jobs dispatchés : '.$dispatched);
        $this->line('Jobs déjà présents ou ignorés : '.$alreadyQueued);
    }
}
