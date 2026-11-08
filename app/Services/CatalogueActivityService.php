<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * M13.3a — suivi d'inactivité des sources du catalogue commun.
 *
 * Règle unique : une source catalogue est inactive ssi elle n'a plus
 * aucun abonnement (`user_sources`) ET plus aucun rattachement
 * (`source_veille`) — deux critères indépendants, existence seule
 * (aucun comptage d'utilisateurs). L'activité technique (collecte,
 * erreurs) et éditoriale (articles) n'intervient pas.
 *
 * `catalogue_inactive_at` posé seulement si NULL (début de la période
 * continue conservé) ; remis à NULL dès qu'un lien réapparaît.
 * Les sources privées ne sont jamais écrites. `orphaned_at` n'est
 * jamais lu ni modifié ici. Aucune suppression — jamais.
 */
class CatalogueActivityService
{
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
                    $source->forceFill(['catalogue_inactive_at' => $target])->saveQuietly();
                }
            });
    }

    /** Vrai ssi source catalogue sans aucun lien utilisateur. */
    public function isInactive(Source $source): bool
    {
        return (bool) $source->is_catalogue
            && ! DB::table('user_sources')->where('source_id', $source->id)->exists()
            && ! DB::table('source_veille')->where('source_id', $source->id)->exists();
    }

    /**
     * Valeur attendue de `catalogue_inactive_at` : date existante
     * conservée, `now` si inactive non marquée, NULL si active.
     * Source privée : valeur actuelle inchangée (jamais candidate).
     */
    public function expectedInactiveAt(Source $source): mixed
    {
        if (! $source->is_catalogue) {
            return $source->catalogue_inactive_at;
        }
        if (! $this->isInactive($source)) {
            return null;
        }

        return $source->catalogue_inactive_at ?? now('UTC');
    }
}
