<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FetchFeedJob;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** M12.16 — visibilité des erreurs de collecte. */
class SourcesErrorFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtre_erreurs_ne_montre_que_sources_defaillantes(): void
    {
        $u = User::factory()->create();
        $ok = Source::factory()->create(['name' => 'SourceOK', 'fail_count' => 0]);
        $ko = Source::factory()->create([
            'name' => 'SourceKO', 'fail_count' => 2,
            'last_error' => 'TimeoutException: connexion expirée',
            'last_fetched_at' => now()->subHour(), 'last_success_at' => now()->subDay(),
        ]);
        foreach ([$ok, $ko] as $s) {
            $u->userSources()->create(['source_id' => $s->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Vue complète : les deux + le bloc d'erreur.
        $r = $this->actingAs($u)->get('/sources')->assertOk();
        $r->assertSee('SourceOK')->assertSee('SourceKO')
            ->assertSee('connexion expirée')->assertSee('Erreur récurrente')
            ->assertSee('Échecs consécutifs : 2');

        // Filtre erreur : seule la défaillante.
        $r = $this->actingAs($u)->get('/sources?error=1')->assertOk();
        $r->assertSee('SourceKO')->assertDontSee('SourceOK')
            ->assertSee('Sources en erreur uniquement');
    }

    public function test_dashboard_lien_vers_filtre_erreurs(): void
    {
        $u = User::factory()->create();
        $s = Source::factory()->create(['fail_count' => 3]);
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $v->sources()->attach($s->id, ['user_id' => $u->id]);

        $r = $this->actingAs($u)->get('/dashboard')->assertOk();
        $html = $r->getContent();
        $this->assertStringContainsString('/sources?error=1', $html);
        $this->assertStringContainsString('en erreur', $html);
    }

    public function test_source_desactivee_label(): void
    {
        $u = User::factory()->create();
        $s = Source::factory()->create([
            'name' => 'SourceMort', 'fail_count' => 10, 'is_active' => false,
        ]);
        $u->userSources()->create(['source_id' => $s->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($u)->get('/sources?error=1')
            ->assertSee('Source désactivée (échecs répétés)');
    }

    public function test_motif_inconnu_explicite(): void
    {
        $u = User::factory()->create();
        $s = Source::factory()->create(['fail_count' => 1, 'last_error' => null]);
        $u->userSources()->create(['source_id' => $s->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($u)->get('/sources?error=1')->assertSee('Motif non enregistré');
    }

}
