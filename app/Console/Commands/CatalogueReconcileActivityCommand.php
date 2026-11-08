<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\GuardsDatabaseWrites;
use App\Models\Source;
use App\Services\CatalogueActivityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M13.3a/b — réconciliation de `catalogue_inactive_at` puis retrait
 * silencieux des sources du catalogue inactives depuis 90 jours.
 *
 * Dry-run par défaut ; --execute pour écrire (garde-fou M13.1d) ;
 * --scheduled pour le scheduler (autorisation par configuration,
 * jamais --force). Retrait = `is_catalogue` à false uniquement, décidé
 * sous verrou. Aucune suppression, `orphaned_at` jamais touché.
 */
class CatalogueReconcileActivityCommand extends Command
{
    use GuardsDatabaseWrites;

    protected $signature = 'catalogue:reconcile-activity
        {--execute : Applique les corrections et retraits (par défaut : analyse seule)}
        {--dry-run : Force l\'analyse sans écriture (option par défaut)}
        {--limit= : Analyse au plus N sources (diagnostic)}
        {--source-id= : Analyse une seule source par son id (diagnostic)}
        {--force : Requis avec --execute hors base de test (garde-fou production)}
        {--scheduled : Exécution planifiée — autorisée par configuration (config/catalogue.php)}';

    protected $description = 'Recalcule `catalogue_inactive_at` et retire du catalogue les sources inactives depuis 90 jours (dry-run par défaut)';

    private const CHUNK = 500;

    public function handle(CatalogueActivityService $activity): int
    {
        if ($this->option('execute') && $this->option('dry-run')) {
            $this->error('Options contradictoires : --execute et --dry-run sont mutuellement exclusives.');

            return self::INVALID;
        }
        if ($this->option('scheduled') && ($this->option('force') || ! $this->option('execute'))) {
            $this->error('--scheduled exige --execute et est incompatible avec --force.');

            return self::INVALID;
        }
        $exec = (bool) $this->option('execute');
        if ($exec && ! ($this->option('scheduled') ? $this->scheduledWriteAllowed() : $this->writeAllowed())) {
            return self::INVALID;
        }
        $mode = $exec ? 'EXECUTE' : 'DRY-RUN';
        $sourceId = $this->option('source-id') !== null ? (int) $this->option('source-id') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $query = Source::query()->where('is_catalogue', true)->orderBy('id');
        if ($sourceId !== null) {
            $query->where('id', $sourceId);
        }
        if ($limit !== null) {
            $query->limit(max(0, $limit));
        }

        $toMark = [];
        $toClear = [];
        $coherent = 0;
        $analysed = 0;
        $errors = 0;

        $query->chunkById(self::CHUNK, function ($sources) use (&$toMark, &$toClear, &$coherent, &$analysed, $activity): void {
            foreach ($sources as $source) {
                $analysed++;
                $inactive = $activity->isInactive($source);
                if ($inactive && $source->catalogue_inactive_at === null) {
                    $toMark[] = $source->id;
                } elseif (! $inactive && $source->catalogue_inactive_at !== null) {
                    $toClear[] = $source->id;
                } else {
                    $coherent++;
                }
            }
        });

        $this->info("catalogue:reconcile-activity [$mode] — $analysed source(s) catalogue analysée(s)");
        $this->line('  à marquer inactives    : '.count($toMark));
        $this->line('  marqueur à retirer     : '.count($toClear));
        $this->line('  déjà cohérentes        : '.$coherent);

        if ($exec) {
            foreach (array_merge($toMark, $toClear) as $sid) {
                try {
                    DB::transaction(fn () => $activity->reevaluate($sid));
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  source #$sid : ".$e->getMessage());
                }
            }
        }

        // Retrait : sélection APRÈS la réconciliation des marqueurs ;
        // chaque décision est reprise sous verrou (liens relus).
        $candidates = $activity->withdrawalCandidateIds($sourceId, $limit);
        $this->line('  à retirer (≥ '.CatalogueActivityService::WITHDRAWAL_DAYS.' j)    : '.count($candidates));

        $withdrawn = 0;
        $skipped = 0;
        if ($exec) {
            foreach ($candidates as $sid) {
                try {
                    $activity->withdrawIfStillInactive($sid) ? $withdrawn++ : $skipped++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  retrait source #$sid : ".$e->getMessage());
                }
            }
            $this->info('  marqueurs corrigés : '.(count($toMark) + count($toClear))
                ." — retirées : $withdrawn — conservées (lien réapparu) : $skipped"
                .($errors ? " — erreurs : $errors" : ''));
        } else {
            $this->info('  aucune écriture (dry-run) — relancer avec --execute pour appliquer.');
        }

        Log::info("catalogue:reconcile-activity $mode analysed:$analysed mark:".count($toMark)
            .' clear:'.count($toClear).' candidates:'.count($candidates)
            ." withdrawn:$withdrawn skipped:$skipped coherent:$coherent errors:$errors");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
