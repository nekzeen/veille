<?php

namespace App\Services;

use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use Illuminate\Support\Facades\DB;

/**
 * Gestion transactionnelle des veilles : la veille, ses mots-clés,
 * ses associations de sources et les abonnements personnels créés
 * automatiquement forment un tout — un échec annule tout.
 *
 * `article_veille` n'est jamais modifié dans la transaction : le
 * rematching §4.D est déclenché après commit par `VeilleRematcher`
 * (création, modification des règles, réactivation), en lecture de
 * données déjà commitées.
 */
final class VeilleService
{
    public function __construct(
        private readonly SourceSubscriptionService $subscriptions,
        private readonly VeilleRematcher $rematcher,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Colonnes de `veilles`.
     * @param  list<string>  $terms  Termes normalisés (trim + minuscules).
     * @param  list<int>  $sourceIds
     */
    public function create(User $user, array $attributes, array $terms, array $sourceIds): Veille
    {
        $veille = DB::transaction(function () use ($user, $attributes, $terms, $sourceIds): Veille {
            $veille = $user->veilles()->create($attributes);

            $this->syncKeywords($veille, $terms);
            $this->syncSources($user, $veille, $sourceIds);

            return $veille;
        });
        // Rematching §4.D après commit : la nouvelle veille hérite des
        // articles historiques pertinents (fenêtre de rétention).
        $this->rematcher->rematch($veille);

        return $veille;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $terms
     * @param  list<int>  $sourceIds
     */
    public function update(Veille $veille, array $attributes, array $terms, array $sourceIds): Veille
    {
        $before = [
            'is_active' => $veille->is_active,
            'terms' => $veille->keywords()->orderBy('id')->pluck('term')->all(),
            'sources' => $veille->sources()->pluck('sources.id')->all(),
        ];

        $veille = DB::transaction(function () use ($veille, $attributes, $terms, $sourceIds): Veille {
            $veille->update($attributes);
            $this->syncKeywords($veille, $terms);
            $this->syncSources($veille->user, $veille, $sourceIds);

            return $veille;
        });

        // Rematching uniquement si les règles ont bougé ou en cas de
        // réactivation — jamais sur une veille qui reste inactive.
        $rulesChanged = $terms !== $before['terms']
            || $sourceIds !== $before['sources']
            || (! $before['is_active'] && $veille->is_active);
        if ($veille->is_active && $rulesChanged) {
            $this->rematcher->rematch($veille);
        }

        return $veille;
    }

    /** Bascule `is_active` uniquement (D11) — rien d'autre n'est touché. */
    public function toggle(Veille $veille): Veille
    {
        $veille->update(['is_active' => ! $veille->is_active]);

        // Réactivation : les correspondances historiques pertinentes
        // sont recalculées (D11 n'efface rien pendant la pause).
        if ($veille->is_active) {
            $this->rematcher->rematch($veille);
        }

        return $veille;
    }

    public function delete(Veille $veille): void
    {
        $veille->delete();
    }

    /**
     * Synchronise les mots-clés par différence : les termes conservés
     * gardent leur ligne (le `created_at` historique ordonne le choix
     * de `matched_keyword`), les nouveaux sont insérés.
     *
     * @param  list<string>  $terms
     */
    private function syncKeywords(Veille $veille, array $terms): void
    {
        $existing = $veille->keywords()->pluck('id', 'term');

        $removed = $existing->except($terms);
        if ($removed->isNotEmpty()) {
            $veille->keywords()->whereIn('id', $removed->values()->all())->delete();
        }

        foreach (array_diff($terms, $existing->keys()->all()) as $term) {
            $veille->keywords()->create(['term' => $term, 'user_id' => $veille->user_id]);
        }
    }

    /**
     * Synchronise les sources de la veille. Pour chaque source non
     * encore abonnée, `subscribe()` crée l'abonnement (catalogue) ou
     * lève RuntimeException (personnelle inaccessible) — la
     * transaction appelante annule alors tout. Retirer une source de
     * la veille ne supprime jamais l'abonnement.
     *
     * @param  list<int>  $sourceIds
     */
    private function syncSources(User $user, Veille $veille, array $sourceIds): void
    {
        // M13.3b — lignes verrouillées (ordre d'id, sans interblocage) :
        // sérialisé avec un retrait du catalogue concurrent.
        $sources = Source::whereIn('id', $sourceIds)->orderBy('id')
            ->lockForUpdate()->get()->keyBy('id');
        $subscribed = $user->userSources()
            ->whereIn('source_id', $sourceIds)->pluck('source_id')->flip();

        $attach = [];
        foreach ($sourceIds as $sourceId) {
            $source = $sources->get($sourceId);
            if ($source === null) {
                continue;
            }
            if (! $subscribed->has($sourceId)) {
                $this->subscriptions->subscribe($user, $source);
            }
            $attach[] = $sourceId;
        }

        $before = $veille->sources()->pluck('sources.id')->all();
        $veille->sources()->sync($attach);
        // M13.3a — rattachements ajoutés/retirés : réévaluer l'activité.
        app(CatalogueActivityService::class)->reevaluate(array_merge($before, $attach));
    }
}
