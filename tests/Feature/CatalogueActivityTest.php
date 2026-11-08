<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Services\CatalogueActivityService;
use App\Services\SourceSubscriptionService;
use App\Services\VeilleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M13.3a — suivi d'inactivité des sources du catalogue. */
class CatalogueActivityTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): CatalogueActivityService
    {
        return app(CatalogueActivityService::class);
    }

    private function catalogue(array $extra = []): Source
    {
        return Source::factory()->catalogue()->create($extra);
    }

    private function subscribe(User $u, Source $s): UserSource
    {
        return app(SourceSubscriptionService::class)->subscribe($u, $s);
    }

    private function attach(Veille $v, Source $s): void
    {
        DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $s->id, 'user_id' => $v->user_id]);
    }

    // ---- Service ----

    public function test_catalogue_sans_lien_marquee(): void
    {
        $s = $this->catalogue();
        $this->svc()->reevaluate($s);
        $this->assertNotNull($s->fresh()->catalogue_inactive_at);
    }

    public function test_date_initiale_preservee(): void
    {
        $start = Carbon::parse('2026-01-01 10:00:00', 'UTC');
        $s = $this->catalogue(['catalogue_inactive_at' => $start]);
        $this->travel(5)->days();
        $this->svc()->reevaluate($s);
        $this->assertTrue($start->equalTo($s->fresh()->catalogue_inactive_at));
    }

    public function test_abonnement_seul_maintient_active(): void
    {
        $s = $this->catalogue(['catalogue_inactive_at' => now()]);
        $this->subscribe(User::factory()->create(), $s);
        $this->assertNull($s->fresh()->catalogue_inactive_at);
    }

    public function test_rattachement_veille_seul_maintient_active(): void
    {
        $s = $this->catalogue(['catalogue_inactive_at' => now()]);
        $this->attach(Veille::factory()->create(), $s);
        $this->svc()->reevaluate($s);
        $this->assertNull($s->fresh()->catalogue_inactive_at);
        $this->assertSame(0, UserSource::where('source_id', $s->id)->count());
    }

    public function test_source_privee_jamais_ecrite(): void
    {
        $a = Source::factory()->create(['is_catalogue' => false]);
        $preset = Carbon::parse('2026-02-02 00:00:00', 'UTC');
        $b = Source::factory()->create(['is_catalogue' => false, 'catalogue_inactive_at' => $preset]);
        $this->svc()->reevaluate([$a, $b]);
        $this->assertNull($a->fresh()->catalogue_inactive_at);
        $this->assertTrue($preset->equalTo($b->fresh()->catalogue_inactive_at));
        $this->assertFalse($this->svc()->isInactive($a));
    }

    public function test_orphaned_at_jamais_modifie(): void
    {
        $o = Carbon::parse('2026-03-03 00:00:00', 'UTC');
        $s = $this->catalogue(['orphaned_at' => $o]);
        $this->svc()->reevaluate($s);
        $this->assertTrue($o->equalTo($s->fresh()->orphaned_at));
        $this->assertNotNull($s->fresh()->catalogue_inactive_at);
    }

    public function test_activite_technique_et_editoriale_ignorees(): void
    {
        $s = $this->catalogue(['last_success_at' => now(), 'last_fetched_at' => now(), 'fail_count' => 0]);
        Article::factory()->create(['source_id' => $s->id, 'published_at' => now()]);
        $this->svc()->reevaluate($s);
        $this->assertNotNull($s->fresh()->catalogue_inactive_at);
    }

    // ---- Points d'intégration ----

    public function test_desabonnement_dernier_lien_marque_sans_suppression(): void
    {
        $u = User::factory()->create();
        $s = $this->catalogue();
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $sub = $this->subscribe($u, $s);
        $this->attach($v, $s); // même utilisateur dans les deux relations
        Article::factory()->create(['source_id' => $s->id]);

        app(SourceSubscriptionService::class)->unsubscribe($u, $sub);

        $fresh = $s->fresh();
        $this->assertNotNull($fresh->catalogue_inactive_at);
        $this->assertTrue((bool) $fresh->is_catalogue); // pas de retrait
        $this->assertSame(1, Article::where('source_id', $s->id)->count());
    }

    public function test_desabonnement_autre_abonne_reste_actif(): void
    {
        $s = $this->catalogue();
        [$u1, $u2] = User::factory()->count(2)->create();
        $sub1 = $this->subscribe($u1, $s);
        $this->subscribe($u2, $s);

        app(SourceSubscriptionService::class)->unsubscribe($u1, $sub1);

        $this->assertNull($s->fresh()->catalogue_inactive_at);
        $this->assertSame(1, UserSource::where('source_id', $s->id)->count());
    }

    public function test_retrait_de_veille_avec_abonnement_reste_active(): void
    {
        $u = User::factory()->create();
        $s = $this->catalogue();
        $veille = app(VeilleService::class)->create($u, ['name' => 'V'], ['ia'], [$s->id]);
        app(VeilleService::class)->update($veille, ['name' => 'V'], ['ia'], []);

        $this->assertSame(0, DB::table('source_veille')->where('source_id', $s->id)->count());
        $this->assertNull($s->fresh()->catalogue_inactive_at); // abonnement conservé
    }

    public function test_suppression_veille_reevalue(): void
    {
        $s = $this->catalogue();
        $v = Veille::factory()->create();
        $this->attach($v, $s);
        $this->actingAs($v->user)->delete(route('veilles.destroy', $v));
        $this->assertNotNull($s->fresh()->catalogue_inactive_at);
    }

    public function test_suppression_compte_reevalue(): void
    {
        $u = User::factory()->create(['password' => 'motdepasse-fort-42']);
        $s = $this->catalogue();
        $this->subscribe($u, $s);
        $this->actingAs($u)->delete('/profile', ['password' => 'motdepasse-fort-42']);
        $this->assertNotNull($s->fresh()->catalogue_inactive_at);
        $this->assertNotNull(Source::find($s->id)); // jamais supprimée
    }

    // ---- Commande ----

    public function test_dry_run_par_defaut_sans_ecriture(): void
    {
        $s = $this->catalogue();
        $snap = DB::table('sources')->where('id', $s->id)->first();
        $this->artisan('catalogue:reconcile-activity')
            ->expectsOutputToContain('[DRY-RUN]')
            ->expectsOutputToContain('à marquer inactives    : 1')
            ->assertExitCode(0);
        $this->assertEquals($snap, DB::table('sources')->where('id', $s->id)->first());
    }

    public function test_execute_marque_et_retire(): void
    {
        $idle = $this->catalogue();
        $used = $this->catalogue();
        DB::table('user_sources')->insert(['user_id' => User::factory()->create()->id,
            'source_id' => $used->id, 'created_at' => now()]);
        DB::table('sources')->where('id', $used->id)->update(['catalogue_inactive_at' => now()]);
        $subs = UserSource::count();

        $this->artisan('catalogue:reconcile-activity', ['--execute' => true])->assertExitCode(0);

        $this->assertNotNull($idle->fresh()->catalogue_inactive_at);
        $this->assertNull($used->fresh()->catalogue_inactive_at);
        $this->assertSame($subs, UserSource::count());
        $this->assertSame(2, Source::count());
    }

    public function test_commande_ignore_sources_privees(): void
    {
        $p = Source::factory()->create(['is_catalogue' => false]);
        $this->artisan('catalogue:reconcile-activity', ['--execute' => true])
            ->expectsOutputToContain('0 source(s) catalogue analysée(s)')
            ->assertExitCode(0);
        $this->assertNull($p->fresh()->catalogue_inactive_at);
        $this->assertNull($p->fresh()->orphaned_at);
    }

    public function test_options_contradictoires(): void
    {
        $this->artisan('catalogue:reconcile-activity', ['--execute' => true, '--dry-run' => true])
            ->assertExitCode(2);
    }

    // ---- Garde-fou (M13.1d) — connexion sqlite dédiée, jamais veille_db ----

    private function withOtherDb(callable $fn): void
    {
        $path = sys_get_temp_dir().'/catalogue_other_'.uniqid().'.sqlite';
        touch($path);
        $orig = config('database.default');
        config(['database.connections.catalogue_other' => [
            'driver' => 'sqlite', 'database' => $path, 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('catalogue_other');
        DB::statement('CREATE TABLE sources (id INTEGER PRIMARY KEY, is_catalogue INTEGER NOT NULL DEFAULT 0, catalogue_inactive_at TEXT NULL)');
        DB::statement('CREATE TABLE user_sources (id INTEGER PRIMARY KEY, source_id INTEGER NOT NULL)');
        DB::statement('CREATE TABLE source_veille (veille_id INTEGER NOT NULL, source_id INTEGER NOT NULL)');
        try {
            $fn($path);
        } finally {
            DB::setDefaultConnection($orig);
            DB::purge('catalogue_other');
            @unlink($path);
        }
    }

    public function test_base_distincte_refusee_sans_force(): void
    {
        $this->withOtherDb(fn () => $this->assertSame(2,
            Artisan::call('catalogue:reconcile-activity', ['--execute' => true])));
    }

    public function test_force_confirmation_exacte_autorisee(): void
    {
        $this->withOtherDb(function (string $path): void {
            $this->artisan('catalogue:reconcile-activity', ['--execute' => true, '--force' => true])
                ->expectsQuestion("Écriture demandée sur la base « $path » — saisissez son nom exact pour confirmer", $path)
                ->assertExitCode(0);
        });
    }

    public function test_force_nom_incorrect_refuse(): void
    {
        $this->withOtherDb(function (string $path): void {
            $this->artisan('catalogue:reconcile-activity', ['--execute' => true, '--force' => true])
                ->expectsQuestion("Écriture demandée sur la base « $path » — saisissez son nom exact pour confirmer", 'veille_db')
                ->assertExitCode(2);
        });
    }

    public function test_force_non_interactif_refuse(): void
    {
        $this->withOtherDb(fn () => $this->assertSame(2,
            Artisan::call('catalogue:reconcile-activity', ['--execute' => true, '--force' => true])));
    }
}
