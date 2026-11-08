<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * M13.1a — cycle de vie centralisé des sources.
 *
 * Règle unique (M8/D16) : orpheline ssi NON catalogue ET plus aucun
 * abonné (`user_sources`), plus aucune veille (`source_veille`) et plus
 * aucun article (`articles`). `orphaned_at` posé seulement si NULL
 * (la date de début d'inutilisation n'est jamais réinitialisée tant
 * que la source reste orpheline) ; NULL dans tout autre cas.
 * Aucune suppression physique — jamais.
 */
class SourceLifecycleService
{
    /**
     * Recalcule l'état d'une ou plusieurs sources après une mutation
     * d'association. Appelé APRÈS la suppression effective des liens —
     * les cascades SQL ne déclenchent aucun événement Eloquent.
     *
     * @param  iterable<Source|int>|Source|int  $sources
     */
    public function reevaluate(iterable|Source|int $sources): void
    {
        $ids = collect(is_iterable($sources) ? $sources : [$sources])
            ->map(fn (Source|int $s) => $s instanceof Source ? $s->id : $s)
            ->unique()->filter()->values();

        Source::whereIn('id', $ids)->each(fn (Source $s) => $this->evaluateOne($s));
    }

    /** Identifiants des sources liées à un utilisateur (abonnements + veilles). */
    public function sourceIdsForUser(int $userId): array
    {
        return DB::table('user_sources')->where('user_id', $userId)->pluck('source_id')
            ->merge(DB::table('source_veille')->where('user_id', $userId)->pluck('source_id'))
            ->unique()->values()->all();
    }

    /** Identifiants des sources rattachées à une veille. */
    public function sourceIdsForVeille(int $veilleId): array
    {
        return DB::table('source_veille')->where('veille_id', $veilleId)
            ->pluck('source_id')->all();
    }

    /**
     * État orphelin attendu (règle unique M8/D16) — réutilisé par
     * sources:reconcile-orphans pour la phase d'analyse.
     */
    public function isExpectedOrphan(Source $source): bool
    {
        return ! $source->is_catalogue
            && ! $source->userSources()->exists()
            && ! DB::table('source_veille')->where('source_id', $source->id)->exists()
            && ! DB::table('articles')->where('source_id', $source->id)->exists();
    }

    private function evaluateOne(Source $source): void
    {
        if ($this->isExpectedOrphan($source)) {
            if ($source->orphaned_at === null) {
                $source->update(['orphaned_at' => now('UTC')]);
            }
        } else {
            $source->update(['orphaned_at' => null]);
        }
    }
}
