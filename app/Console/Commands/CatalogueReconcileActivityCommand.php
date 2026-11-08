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
 * M13.3a — réconciliation de `catalogue_inactive_at` (sources
 * catalogue uniquement). Dry-run par défaut ; --execute pour écrire
 * (garde-fou M13.1d). Aucun retrait du catalogue, aucune suppression,
 * `orphaned_at` jamais touché.
 */
class CatalogueReconcileActivityCommand extends Command
{
    use GuardsDatabaseWrites;

    protected $signature = 'catalogue:reconcile-activity
        {--execute : Applique les corrections (par défaut : analyse seule)}
        {--dry-run : Force l\'analyse sans écriture (option par défaut)}
        {--limit= : Analyse au plus N sources (diagnostic)}
        {--source-id= : Analyse une seule source par son id (diagnostic)}
        {--force : Requis avec --execute hors base de test (garde-fou production)}';

    protected $description = 'Recalcule `catalogue_inactive_at` des sources du catalogue (dry-run par défaut)';

    private const CHUNK = 500;

    public function handle(CatalogueActivityService $activity): int
    {
        if ($this->option('execute') && $this->option('dry-run')) {
            $this->error('Options contradictoires : --execute et --dry-run sont mutuellement exclusives.');

            return self::INVALID;
        }
        $exec = (bool) $this->option('execute');
        if ($exec && ! $this->writeAllowed()) {
            return self::INVALID;
        }
        $mode = $exec ? 'EXECUTE' : 'DRY-RUN';

        $query = Source::query()->where('is_catalogue', true)->orderBy('id');
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

        if ($exec && ($toMark || $toClear)) {
            foreach (array_merge($toMark, $toClear) as $sid) {
                try {
                    DB::transaction(fn () => $activity->reevaluate($sid));
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error("  source #$sid : ".$e->getMessage());
                }
            }
            $done = count($toMark) + count($toClear) - $errors;
            $this->info("  corrigées : $done".($errors ? " — erreurs : $errors" : ''));
        } elseif (! $exec) {
            $this->info('  aucune écriture (dry-run) — relancer avec --execute pour corriger.');
        }

        Log::info("catalogue:reconcile-activity $mode analysed:$analysed mark:".count($toMark)
            .' clear:'.count($toClear)." coherent:$coherent errors:$errors");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
