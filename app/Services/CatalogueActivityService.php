<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * M14 — suivi d'inactivité et retrait des sources du catalogue commun.
 *
 * Toutes les sources sont communes (`is_catalogue` toujours vrai) ;
 * l'état de publication est `catalogue_state` : 'active' (proposée
 * dans le catalogue) ou 'withdrawn' (retirée, toujours commune —
 * jamais une source privée). `orphaned_at` est dormante (M14.1).
 *
 * Inactivité : source 'active' sans aucun abonnement (`user_sources`)
 * ET aucun rattachement (`source_veille`) — critères indépendants,
 * existence seule. `catalogue_inactive_at` posé si NULL, remis à NULL
 * dès qu'un lien réapparaît ou dès que la source quitte 'active'.
 *
 * Retrait : après WITHDRAWAL_DAYS jours continus, `catalogue_state`
 * passe à 'withdrawn' et le marqueur est effacé — `is_catalogue` reste
 * vrai, les liens et données sont conservés. Réactivation uniquement
 * via `republish()` après revalidation complète du flux.
 *
 * Sérialisation : retrait et abonnements verrouillent la même ligne
 * `sources` (FOR UPDATE) ; les liens sont relus sous verrou.
 * `orphaned_at` et `updated_at` ne sont jamais modifiés ici.
 */
class CatalogueActivityService
{
    public const WITHDRAWAL_DAYS = 90;

    /** @param  iterable<Source|int>|Source|int  $sources */
    public function reevaluate(iterable|Source|int $sources): void
    {
        $ids = collect(is_iterable($sources) ? $sources : [$sources])
            ->map(fn (Source|int $s) => $s instanceof Source ? $s->id : $s)
            ->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return;
        }

        Source::whereIn('id', $ids)->where('is_catalogue', true)
            ->each(function (Source $source): void {
                $target = $this->expectedInactiveAt($source);
                if ($target !== $source->catalogue_inactive_at) {
                    DB::table('sources')->where('id', $source->id)
                        ->update(['catalogue_inactive_at' => $target]);
                }
            });
    }

    /** Vrai ssi source active au catalogue sans aucun lien utilisateur. */
    public function isInactive(Source $source): bool
    {
        return (bool) $source->is_catalogue
            && $source->catalogue_state === 'active'
            && ! $this->hasLinks($source->id);
    }

    /** Retirée du catalogue actif : commune, réactivable après revalidation. */
    public function isWithdrawn(Source $source): bool
    {
        return $source->catalogue_state === 'withdrawn';
    }

    /**
     * Valeur attendue de `catalogue_inactive_at` : date existante
     * conservée, `now` si inactive non marquée, NULL si active,
     * retirée ou hors catalogue.
     */
    public function expectedInactiveAt(Source $source): mixed
    {
        if (! $source->is_catalogue || $source->catalogue_state !== 'active') {
            return null;
        }
        if (! $this->isInactive($source)) {
            return null;
        }

        return $source->catalogue_inactive_at ?? now('UTC');
    }

    /** Date limite : inactive depuis au moins WITHDRAWAL_DAYS jours. */
    public function cutoff(): CarbonInterface
    {
        return now('UTC')->subDays(self::WITHDRAWAL_DAYS);
    }

    /**
     * Candidates au retrait (lecture seule) : actives, marquées
     * avant la date limite, sans aucun lien. La décision finale est
     * reprise sous verrou par withdrawIfStillInactive().
     *
     * @return list<int>
     */
    public function withdrawalCandidateIds(?int $sourceId = null, ?int $limit = null): array
    {
        return Source::query()
            ->where('is_catalogue', true)
            ->where('catalogue_state', 'active')
            ->whereNotNull('catalogue_inactive_at')
            ->where('catalogue_inactive_at', '<=', $this->cutoff())
            ->whereNotExists(fn ($q) => $q->from('user_sources')->whereColumn('user_sources.source_id', 'sources.id'))
            ->whereNotExists(fn ($q) => $q->from('source_veille')->whereColumn('source_veille.source_id', 'sources.id'))
            ->when($sourceId !== null, fn ($q) => $q->where('id', $sourceId))
            ->orderBy('id')
            ->when($limit !== null, fn ($q) => $q->limit(max(0, (int) $limit)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Retrait sous verrou : ligne source verrouillée FOR UPDATE, puis
     * revérification complète (active, délai, liens relus en lecture
     * verrouillante). Seul `catalogue_state` bascule à 'withdrawn' ;
     * `is_catalogue` reste vrai, `orphaned_at` jamais écrit.
     */
    public function withdrawIfStillInactive(int $sourceId): bool
    {
        return DB::transaction(function () use ($sourceId): bool {
            $source = Source::whereKey($sourceId)->lockForUpdate()->first();
            if ($source === null
                || ! $source->is_catalogue
                || $source->catalogue_state !== 'active'
                || $source->catalogue_inactive_at === null
                || $source->catalogue_inactive_at->greaterThan($this->cutoff())
                || $this->hasLinks($sourceId, lock: true)) {
                return false;
            }

            DB::table('sources')->where('id', $sourceId)
                ->update(['catalogue_state' => 'withdrawn', 'catalogue_inactive_at' => null]);

            return true;
        });
    }

    /**
     * Réactivation explicite (M14) — à appeler UNIQUEMENT après
     * revalidation complète du flux (SourceDetector/SecureFeedClient)
     * et sur une ligne déjà verrouillée. Même enregistrement ;
     * thématique existante conservée, sinon thématique fournie.
     */
    public function republish(Source $source, int $themeId): void
    {
        $attributes = ['is_catalogue' => true, 'catalogue_state' => 'active', 'catalogue_inactive_at' => null];
        if ($source->theme_id === null) {
            $attributes['theme_id'] = $themeId;
        }
        DB::table('sources')->where('id', $source->id)->update($attributes);
        $source->forceFill($attributes)->syncOriginal();
    }

    private function hasLinks(int $sourceId, bool $lock = false): bool
    {
        $subs = DB::table('user_sources')->where('source_id', $sourceId);
        $veilles = DB::table('source_veille')->where('source_id', $sourceId);
        if ($lock) {
            $subs->sharedLock();
            $veilles->sharedLock();
        }

        return $subs->exists() || $veilles->exists();
    }
}
