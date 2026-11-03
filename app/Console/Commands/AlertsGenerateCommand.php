<?php

namespace App\Console\Commands;

use App\Models\Veille;
use App\Models\VeilleAlert;
use App\Services\Alerts\DigestWindowCalculator;
use App\Services\ArticleVeilleWriter;
use App\Services\SourceSubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Génération quotidienne des digests (DataModel §3.11, M5.2) : pour
 * chaque veille active dont les alertes sont activées, une alerte
 * `pending` couvrant les nouvelles correspondances de la fenêtre
 * ]start_match_id, end_match_id]. Aucune alerte quand aucune
 * correspondance n'est éligible — jamais de digest vide, jamais
 * d'avancement du curseur sans contenu.
 *
 * Chaque veille est traitée dans sa propre transaction sous le
 * verrou `FOR UPDATE` partagé avec {@see ArticleVeilleWriter}
 * : le watermark `MAX(article_veille.id)` capturé sous ce verrou ne
 * peut pas être dépassé par une insertion non commitée. L'unicité
 * `(veille_id, digest_date)` reste le garde-fou des générations
 * concurrentes. `digest_date` = jour civil Europe/Paris ; les
 * timestamps stockés restent UTC (period_* = affichage).
 */
class AlertsGenerateCommand extends Command
{
    protected $signature = 'alerts:generate';

    protected $description = 'Génère les alertes quotidiennes (digests) des veilles dont les alertes sont activées.';

    public function handle(DigestWindowCalculator $calculator): int
    {
        $today = now('Europe/Paris')->toDateString();
        $generated = 0;

        Veille::where('is_active', true)->where('alerts_enabled', true)
            ->orderBy('id')
            ->chunkById(200, function ($veilles) use ($calculator, $today, &$generated): void {
                foreach ($veilles as $veille) {
                    if ($this->generateFor($veille, $calculator, $today)) {
                        $generated++;
                    }
                }
            });

        $this->info("{$generated} alerte(s) générée(s).");

        return self::SUCCESS;
    }

    private function generateFor(Veille $veille, DigestWindowCalculator $calculator, string $today): bool
    {
        return DB::transaction(function () use ($veille, $calculator, $today): bool {
            // Même verrou que les écrivains de correspondances : la
            // fenêtre est stable pendant tout le traitement.
            $locked = Veille::whereKey($veille->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->is_active || ! $locked->alerts_enabled) {
                return false;
            }

            // Une alerte non résolue couvre la même fenêtre : pas de
            // doublon de génération (UNIQUE(veille_id,digest_date)
            // borne aussi les générations concurrentes).
            $hasOpenAlert = VeilleAlert::where('veille_id', $locked->id)
                ->whereIn('status', [VeilleAlert::STATUS_PENDING, VeilleAlert::STATUS_PROCESSING])
                ->exists();
            if ($hasOpenAlert) {
                return false;
            }

            $startMatchId = $calculator->openCursor($locked);
            $watermark = $calculator->watermark($locked);
            $matches = $calculator->select($locked, $startMatchId, $watermark);
            if ($matches->isEmpty()) {
                return false;
            }

            $lastSent = VeilleAlert::where('veille_id', $locked->id)
                ->where('status', VeilleAlert::STATUS_SENT)
                ->latest('id')->first();

            try {
                VeilleAlert::create([
                    'veille_id' => $locked->id,
                    'user_id' => $locked->user_id,
                    'digest_date' => $today,
                    'status' => VeilleAlert::STATUS_PENDING,
                    'period_start' => $lastSent !== null ? $lastSent->period_end : $locked->created_at,
                    'period_end' => now('UTC'),
                    'start_match_id' => $startMatchId,
                    'end_match_id' => $matches->last()->id,
                ]);
            } catch (QueryException $e) {
                // Garde-fou UNIQUE(veille_id, digest_date) : génération
                // concurrente ou relance le même jour → absorbée.
                if (! SourceSubscriptionService::isUniqueViolation($e)) {
                    throw $e;
                }

                return false;
            }

            return true;
        });
    }
}
