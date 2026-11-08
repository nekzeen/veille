<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Source;
use App\Services\SourceLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M13.1b — réconciliation historique de `orphaned_at`.
 *
 * Compare l'état réel des associations de chaque source avec son
 * marqueur et corrige via SourceLifecycleService (règle unique M8/D16).
 * Dry-run par défaut ; --execute explicite pour écrire. Jamais de
 * suppression de source ni d'autre champ modifié.
 */
class SourcesReconcileOrphansCommand extends Command
{
    protected $signature = 'sources:reconcile-orphans
        {--execute : Applique les corrections (par défaut : analyse seule)}
        {--dry-run : Force l\'analyse sans écriture (option par défaut)}
        {--limit= : Analyse au plus N sources (diagnostic)}
        {--source-id= : Analyse une seule source par son id (diagnostic)}
        {--force : Requis avec --execute hors base de test (garde-fou production)}';

    protected $description = 'Recalcule `orphaned_at` des sources (dry-run par défaut, --execute pour corriger)';

    private const CHUNK = 500;

    public function handle(SourceLifecycleService $lifecycle): int
    {
        if ($this->option('execute') && $this->option('dry-run')) {
            $this->error('Options contradictoires : --execute et --dry-run sont mutuellement exclusives.');

            return self::INVALID;
        }
        $exec = (bool) $this->option('execute');

        // Garde-fou (M13.1d) : --execute n'écrit librement que sur les
        // bases de test (nom réel de la connexion, jamais APP_ENV).
        // Ailleurs : --force exigé + confirmation interactive — saisir
        // le NOM EXACT de la base. Non interactif / saisie fausse = refus.
        $db = (string) DB::connection()->getDatabaseName();
        if ($exec && $db === '') {
            $this->error('--execute refusé : nom de base indéterminé — configuration ambiguë.');

            return self::INVALID;
        }
        $isTestDb = $db === ':memory:' || $db === 'veille_test';
        if ($exec && ! $isTestDb) {
            if (! $this->option('force')) {
                $this->error("--execute refusé : base « $db » hors environnement de test — ajouter --force.");

                return self::INVALID;
            }
            if (! $this->input->isInteractive()) {
                $this->error('--force insuffisant : exécution non interactive — confirmation impossible.');

                return self::INVALID;
            }
            try {
                $typed = $this->ask("Écriture demandée sur la base « $db » — saisissez son nom exact pour confirmer");
            } catch (\Throwable) {
                $typed = null; // saisie interrompue / flux vide = refus
            }
            if ($typed !== $db) {
                $this->error('Confirmation échouée — aucune donnée modifiée.');

                return self::INVALID;
            }
        }
        $mode = $exec ? 'EXECUTE' : 'DRY-RUN';

        $query = Source::query()->orderBy('id');
        if (($id = $this->option('source-id')) !== null) {
            $query->where('id', (int) $id);
        }
        if (($limit = $this->option('limit')) !== null) {
            $query->limit(max(0, (int) $limit));
        }

        $toMark = [];
        $toClear = [];
        $coherent = 0;
        $analysed = 0;
        $errors = 0;

        $query->chunkById(self::CHUNK, function ($sources) use (&$toMark, &$toClear, &$coherent, &$analysed, $lifecycle): void {
            foreach ($sources as $source) {
                $analysed++;
                $expected = $this->expectedOrphan($source, $lifecycle);
                if ($expected && $source->orphaned_at === null) {
                    $toMark[] = $source->id;
                } elseif (! $expected && $source->orphaned_at !== null) {
                    $toClear[] = $source->id;
                } else {
                    $coherent++;
                }
            }
        });

        $this->info("sources:reconcile-orphans [$mode] — $analysed source(s) analysée(s)");
        $this->line('  à marquer orphelines   : '.count($toMark));
        $this->line('  marqueur à retirer     : '.count($toClear));
        $this->line('  déjà cohérentes        : '.$coherent);

        if ($exec && ($toMark || $toClear)) {
            foreach (array_merge($toMark, $toClear) as $id) {
                try {
                    DB::transaction(fn () => $lifecycle->reevaluate($id));
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  source #$id : ".$e->getMessage());
                }
            }
            $done = count($toMark) + count($toClear) - $errors;
            $this->info("  corrigées : $done".($errors ? " — erreurs : $errors" : ''));
        } elseif (! $exec) {
            $this->info('  aucune écriture (dry-run) — relancer avec --execute pour corriger.');
        }

        Log::info("sources:reconcile-orphans $mode analysed:$analysed mark:".count($toMark)
            .' clear:'.count($toClear)." coherent:$coherent errors:$errors");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Délégation — règle unique dans SourceLifecycleService (M13.1c). */
    private function expectedOrphan(Source $source, SourceLifecycleService $lifecycle): bool
    {
        return $lifecycle->isExpectedOrphan($source);
    }
}
