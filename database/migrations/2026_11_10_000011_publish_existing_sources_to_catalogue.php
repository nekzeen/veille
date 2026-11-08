<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M14.3 — publication des sources existantes dans le catalogue commun.
 *
 * Seules les 22 sources réelles identifiées sont concernées ; les
 * données de test (10, 11) et le flux de test (2072) sont exclus et
 * restent hors catalogue actif (catalogue_state='withdrawn').
 * Thématiques résolues par slug — jamais d'ids en dur.
 * Idempotente : re-jouable sans effet (mêmes valeurs écrites).
 * Aucun abonnement, veille, article ni donnée technique modifié.
 * `catalogue_inactive_at` recalculé : les sources déjà liées restent
 * à NULL ; les sources publiées SANS aucun lien reçoivent une date
 * d'inactivité (la réconciliation quotidienne la confirmerait de même).
 */
return new class extends Migration
{
    private const PUBLISH = [
        1 => 'actualites-societe',
        2 => 'actualites-societe',
        3 => 'autres',
        4 => 'actualites-societe',
        5 => 'formation-enseignement',
        2067 => 'formation-enseignement',
        2068 => 'formation-enseignement',
        2069 => 'formation-enseignement',
        2070 => 'formation-enseignement',
        2071 => 'formation-enseignement',
        2073 => 'formation-enseignement',
        2074 => 'formation-enseignement',
        2075 => 'economie-entreprises',
        2076 => 'formation-enseignement',
        2077 => 'autres',
        2078 => 'formation-enseignement',
        2079 => 'formation-enseignement',
        2080 => 'actualites-societe',
        2081 => 'formation-enseignement',
        2082 => 'formation-enseignement',
        2083 => 'formation-enseignement',
        2084 => 'formation-enseignement',
    ];

    /** Données de test / flux morts : jamais publiées. */
    private const EXCLUDE = [10, 11, 2072];

    public function up(): void
    {
        // Référentiel : si la table est vide (base fraîche de test),
        // les 8 thématiques connues sont créées (même liste que le
        // seeder — idempotent). Si elle est remplie mais qu'un slug
        // requis manque, la précondition échoue explicitement.
        if (DB::table('source_themes')->count() === 0) {
            $known = [
                'informatique-numerique' => 'Informatique et numérique',
                'developpement-programmation' => 'Développement et programmation',
                'sciences-technologies' => 'Sciences et technologies',
                'economie-entreprises' => 'Économie et entreprises',
                'actualites-societe' => 'Actualités et société',
                'culture-medias' => 'Culture et médias',
                'formation-enseignement' => 'Formation et enseignement',
                'autres' => 'Autres',
            ];
            foreach ($known as $slug => $name) {
                DB::table('source_themes')->insert([
                    'name' => $name, 'slug' => $slug,
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
        }
        $themes = DB::table('source_themes')->pluck('id', 'slug');
        foreach (self::PUBLISH as $slug) {
            if (! $themes->has($slug)) {
                throw new RuntimeException("Thématique manquante : $slug — seeder non exécuté.");
            }
        }

        foreach (self::PUBLISH as $sourceId => $slug) {
            $exists = DB::table('sources')->where('id', $sourceId)->exists();
            if (! $exists) {
                Log::warning("migration M14.3 : source #$sourceId absente — ignorée.");

                continue;
            }
            $hasLinks = DB::table('user_sources')->where('source_id', $sourceId)->exists()
                || DB::table('source_veille')->where('source_id', $sourceId)->exists();
            DB::table('sources')->where('id', $sourceId)->update([
                'is_catalogue' => true,
                'catalogue_state' => 'active',
                'theme_id' => $themes[$slug],
                'catalogue_inactive_at' => $hasLinks ? null : now('UTC'),
            ]);
        }

        // Exclues : communes mais retirées du catalogue actif (M14).
        DB::table('sources')->whereIn('id', self::EXCLUDE)
            ->where('is_catalogue', true)
            ->update(['catalogue_state' => 'withdrawn']);
    }

    public function down(): void
    {
        // Données : aucune réversion automatique fiable (référencer la
        // sauvegarde préalable). Structure inchangée par cette migration.
    }
};
