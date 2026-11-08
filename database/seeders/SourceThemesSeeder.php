<?php

namespace Database\Seeders;

use App\Models\SourceTheme;
use Illuminate\Database\Seeder;

/**
 * M13.2a — peuplement du référentiel de thématiques.
 * Idempotent : updateOrCreate par slug — réexécution sans doublons.
 */
class SourceThemesSeeder extends Seeder
{
    /** @var array<int, array{name: string, slug: string}> */
    private const THEMES = [
        ['name' => 'Informatique et numérique', 'slug' => 'informatique-numerique'],
        ['name' => 'Développement et programmation', 'slug' => 'developpement-programmation'],
        ['name' => 'Sciences et technologies', 'slug' => 'sciences-technologies'],
        ['name' => 'Économie et entreprises', 'slug' => 'economie-entreprises'],
        ['name' => 'Actualités et société', 'slug' => 'actualites-societe'],
        ['name' => 'Culture et médias', 'slug' => 'culture-medias'],
        ['name' => 'Formation et enseignement', 'slug' => 'formation-enseignement'],
        ['name' => 'Autres', 'slug' => 'autres'],
    ];

    public function run(): void
    {
        foreach (self::THEMES as $theme) {
            SourceTheme::updateOrCreate(['slug' => $theme['slug']], $theme);
        }
    }
}
