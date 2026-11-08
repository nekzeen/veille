<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Source;
use App\Models\SourceTheme;
use App\Models\User;
use App\Models\UserSource;
use Database\Seeders\SourceThemesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M13.2d — interface du catalogue commun. */
class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SourceThemesSeeder::class);
        $this->user = User::factory()->create();
    }

    private function catSource(array $extra = []): Source
    {
        return Source::factory()->create(array_merge([
            'is_catalogue' => true, 'is_active' => true,
            'theme_id' => SourceTheme::firstOrFail()->id,
        ], $extra));
    }

    public function test_invite_redirige_vers_login(): void
    {
        $this->get('/sources/catalogue')->assertRedirect('/login');
    }

    public function test_seules_les_sources_catalogue_affichees(): void
    {
        $this->catSource(['name' => 'Public Feed', 'fail_count' => 0]);
        Source::factory()->create(['is_catalogue' => false, 'name' => 'Private Secret']);

        $this->actingAs($this->user)->get('/sources/catalogue')
            ->assertOk()
            ->assertSee('Public Feed')
            ->assertDontSee('Private Secret');
    }

    public function test_recherche_textuelle(): void
    {
        $this->catSource(['name' => 'Alpha News']);
        $this->catSource(['name' => 'Beta Flux', 'feed_url' => 'https://beta.fr/feed']);

        $this->actingAs($this->user)->get('/sources/catalogue?q=alpha')
            ->assertOk()
            ->assertSee('Alpha News')
            ->assertDontSee('Beta Flux');
    }

    public function test_filtre_thematique_et_toutes_listees(): void
    {
        [$t1, $t2] = SourceTheme::orderBy('id')->take(2)->get()->all();
        $this->catSource(['name' => 'Thème1 Feed', 'theme_id' => $t1->id]);
        $this->catSource(['name' => 'Thème2 Feed', 'theme_id' => $t2->id]);

        $r = $this->actingAs($this->user)->get('/sources/catalogue?theme='.$t2->id)
            ->assertOk()
            ->assertSee('Thème2 Feed')
            ->assertDontSee('Thème1 Feed');
        // Toutes les thématiques listées dans le select, même vides.
        foreach (SourceTheme::all() as $t) {
            $r->assertSee(e($t->name), false);
        }
    }

    public function test_combinaison_recherche_et_theme(): void
    {
        [$t1, $t2] = SourceTheme::orderBy('id')->take(2)->get()->all();
        $this->catSource(['name' => 'Alpha T1', 'theme_id' => $t1->id]);
        $this->catSource(['name' => 'Alpha T2', 'theme_id' => $t2->id]);

        $this->actingAs($this->user)->get('/sources/catalogue?q=alpha&theme='.$t2->id)
            ->assertOk()
            ->assertSee('Alpha T2')
            ->assertDontSee('Alpha T1');
    }

    public function test_pagination_conserve_les_filtres(): void
    {
        Source::factory()->count(21)->create(['is_catalogue' => true, 'is_active' => true,
            'theme_id' => SourceTheme::firstOrFail()->id]);

        $this->actingAs($this->user)->get('/sources/catalogue?q=&theme='.SourceTheme::firstOrFail()->id.'&page=2')
            ->assertOk()
            ->assertSee('theme='.SourceTheme::firstOrFail()->id, false);
    }

    public function test_abonnement_depuis_le_catalogue(): void
    {
        $src = $this->catSource();
        $this->actingAs($this->user)->post("/sources/{$src->id}/subscribe");
        $this->assertDatabaseHas('user_sources', ['user_id' => $this->user->id, 'source_id' => $src->id]);
    }

    public function test_abonnement_deja_existant_affiche_abonne(): void
    {
        $src = $this->catSource();
        UserSource::create(['user_id' => $this->user->id, 'source_id' => $src->id, 'created_at' => now()]);

        $this->actingAs($this->user)->get('/sources/catalogue')
            ->assertOk()
            ->assertSee('Abonné');
        $this->assertSame(1, UserSource::where('user_id', $this->user->id)->count());
    }

    public function test_source_privee_refusee_a_l_abonnement(): void
    {
        $src = Source::factory()->create(['is_catalogue' => false]);
        $this->actingAs($this->user)->post("/sources/{$src->id}/subscribe")
            ->assertForbidden();
    }

    public function test_pas_de_fuite_donnees_personnelles(): void
    {
        $src = $this->catSource();
        $other = User::factory()->create();
        UserSource::create(['user_id' => $other->id, 'source_id' => $src->id,
            'custom_name' => 'Mon nom secret', 'created_at' => now()]);

        $this->actingAs($this->user)->get('/sources/catalogue')
            ->assertOk()
            ->assertDontSee('Mon nom secret')
            ->assertDontSee($other->email);
    }

    public function test_erreurs_techniques_non_exposees(): void
    {
        $this->catSource(['last_error' => 'SSRF internal 10.0.0.5 forbidden', 'fail_count' => 3]);
        $this->actingAs($this->user)->get('/sources/catalogue')
            ->assertOk()
            ->assertDontSee('10.0.0.5')
            ->assertDontSee('last_error');
    }

    public function test_navigation_contient_le_lien_catalogue(): void
    {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Catalogue');
    }
}
