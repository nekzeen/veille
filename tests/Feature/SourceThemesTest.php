<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceTheme;
use Database\Seeders\SourceThemesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** M13.2a — référentiel de thématiques + sources.theme_id. */
class SourceThemesTest extends TestCase
{
    use RefreshDatabase;

    public function test_huit_thematiques_creees_par_le_seeder(): void
    {
        $this->seed(SourceThemesSeeder::class);
        $this->assertSame(8, SourceTheme::count());
        $this->assertTrue(SourceTheme::where('slug', 'developpement-programmation')->exists());
    }

    public function test_slug_unique_en_base(): void
    {
        $this->seed(SourceThemesSeeder::class);
        $this->assertSame(8, SourceTheme::distinct()->count('slug'));
        // Tentative de doublon de slug → violation UNIQUE attendue.
        $this->expectException(QueryException::class);
        SourceTheme::create(['name' => 'Doublon', 'slug' => 'autres']);
    }

    public function test_source_peut_etre_associee_a_une_thematique(): void
    {
        $this->seed(SourceThemesSeeder::class);
        $theme = SourceTheme::where('slug', 'culture-medias')->firstOrFail();
        $src = Source::factory()->create(['theme_id' => $theme->id]);

        $this->assertTrue($src->fresh()->theme->is($theme));
        $this->assertTrue($theme->sources->contains($src->id));
    }

    public function test_source_sans_thematique_reste_valide(): void
    {
        $src = Source::factory()->create(['theme_id' => null]);
        $this->assertNull($src->fresh()->theme_id);
        $this->assertNull($src->fresh()->theme);
    }

    public function test_seeder_idempotent_sans_doublons(): void
    {
        $this->seed(SourceThemesSeeder::class);
        $first = SourceTheme::pluck('id', 'slug')->all();
        $this->seed(SourceThemesSeeder::class); // réexécution
        $this->assertSame(8, SourceTheme::count());
        $this->assertSame($first, SourceTheme::pluck('id', 'slug')->all()); // ids stables
    }

    public function test_schema_colonnes_et_fk(): void
    {
        $this->assertTrue(Schema::hasTable('source_themes'));
        $this->assertTrue(Schema::hasColumn('sources', 'theme_id'));
    }
}
