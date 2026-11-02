<?php

namespace App\Console\Commands;

use App\Models\Veille;
use App\Services\VeilleRematcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rematching global de maintenance (§4.D) : recalcule les
 * correspondances des veilles actives sur la fenêtre de rétention.
 *
 * Parcours par identifiant stable (`chunkById`), une transaction
 * par veille, reprise possible par ré-exécution (idempotence).
 * Non planifiée automatiquement : lancement manuel/cron explicite.
 */
final class VeillesRematchCommand extends Command
{
    protected $signature = 'veilles:rematch {--veille= : Limiter à une veille précise (id)}';

    protected $description = 'Recalcule les associations article_veille (fenêtre de rétention 6 mois)';

    public function handle(VeilleRematcher $rematcher): int
    {
        $totals = ['veilles' => 0, 'examined' => 0, 'expected' => 0, 'kept' => 0, 'created' => 0, 'deleted' => 0];

        $query = Veille::where('is_active', true)->orderBy('id');
        if (($id = $this->option('veille')) !== null) {
            $query->whereKey((int) $id);
        }

        $query->chunkById(100, function ($veilles) use ($rematcher, &$totals): void {
            foreach ($veilles as $veille) {
                $result = $rematcher->rematch($veille);
                $totals['veilles']++;
                $totals['examined'] += $result->examined;
                $totals['expected'] += $result->expected;
                $totals['kept'] += $result->kept;
                $totals['created'] += $result->created;
                $totals['deleted'] += $result->deleted;
                Log::info('veilles.rematch.veille', ['veille_id' => $veille->id] + get_object_vars($result));
            }
        });

        Log::info('veilles.rematch.finished', $totals);
        $this->info(json_encode($totals));

        return self::SUCCESS;
    }
}
