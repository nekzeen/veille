<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M13.1b — sources:reconcile-orphans (dry-run défaut + --execute). */
class SourcesReconcileOrphansTest extends TestCase
{
    use RefreshDatabase;

    private function privateSource(array $extra = []): Source
    {
        return Source::factory()->create(array_merge(['catalogue_state' => 'withdrawn'], $extra));
    }

    private function subscribe(int $uid, int $sid): void
    {
        DB::table('user_sources')->insert(['user_id' => $uid, 'source_id' => $sid, 'created_at' => now()]);
    }

    public function test_dry_run_ne_modifie_aucune_donnee(): void
    {
        $src = $this->privateSource(); // orpheline réelle, non marquée
        $this->artisan('sources:reconcile-orphans')->assertExitCode(0);
        $this->assertNull($src->fresh()->orphaned_at); // inchangé
    }

    public function test_execute_marque_source_reellement_orpheline(): void
    {
        $src = $this->privateSource();
        $this->artisan('sources:reconcile-orphans', ['--execute' => true])->assertExitCode(0);
        $this->assertNotNull($src->fresh()->orphaned_at);
    }

    public function test_source_avec_abonne_non_orpheline(): void
    {
        $u = User::factory()->create();
        $src = $this->privateSource();
        $this->subscribe($u->id, $src->id);
        $this->artisan('sources:reconcile-orphans', ['--execute' => true])->assertExitCode(0);
        $this->assertNull($src->fresh()->orphaned_at);
    }

    public function test_source_associee_a_une_veille_non_orpheline(): void
    {
        $u = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $src = $this->privateSource();
        DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $src->id, 'user_id' => $u->id]);
        $this->artisan('sources:reconcile-orphans', ['--execute' => true])->assertExitCode(0);
        $this->assertNull($src->fresh()->orphaned_at);
    }

    public function test_source_avec_articles_non_orpheline(): void
    {
        $src = $this->privateSource();
        Article::factory()->create(['source_id' => $src->id]);
        $this->artisan('sources:reconcile-orphans', ['--execute' => true])->assertExitCode(0);
        $this->assertNull($src->fresh()->orphaned_at);
    }

    public function test_source_catalogue_jamais_marquee(): void
    {
        $src = Source::factory()->catalogue()->create();
        $this->artisan('sources:reconcile-orphans', ['--execute' => true])->assertExitCode(0);
        $this->assertNull($src->fresh()->orphaned_at);
    }

    public function test_source_deja_orpheline_conserve_sa_date(): void
    {
        $date = now()->subDays(30);
        $src = $this->privateSource(['orphaned_at' => $date]);
        $this->artisan('sources:reconcile-orphans', ['--execute' => true])->assertExitCode(0);
        $this->assertEquals($date->toDateTimeString(), $src->fresh()->orphaned_at);
    }

    public function test_marqueur_retire_si_source_redevenue_utilisee(): void
    {
        $u = User::factory()->create();
        $src = $this->privateSource(['orphaned_at' => now()->subDays(10)]);
        $this->subscribe($u->id, $src->id); // réutilisée — incohérent
        $this->artisan('sources:reconcile-orphans', ['--execute' => true])->assertExitCode(0);
        $this->assertNull($src->fresh()->orphaned_at);
    }

    public function test_execute_ne_modifie_aucun_autre_champ(): void
    {
        $src = $this->privateSource(['name' => 'NomOrigine', 'fail_count' => 3, 'is_active' => false]);
        $this->artisan('sources:reconcile-orphans', ['--execute' => true])->assertExitCode(0);
        $f = $src->fresh();
        $this->assertSame('NomOrigine', $f->name);
        $this->assertSame(3, $f->fail_count);
        $this->assertFalse((bool) $f->is_active);
        $this->assertSame('withdrawn', $f->catalogue_state);
    }

    public function test_lots_sur_volume_superieur_au_chunk(): void
    {
        Source::factory()->count(3)->create(['catalogue_state' => 'withdrawn']);
        $code = Artisan::call('sources:reconcile-orphans', ['--execute' => true]);
        $this->assertSame(0, $code);
        $this->assertSame(3, Source::whereNotNull('orphaned_at')->count());
    }

    public function test_options_contradictoires_refusees(): void
    {
        $this->artisan('sources:reconcile-orphans', ['--execute' => true, '--dry-run' => true])
            ->assertExitCode(2); // INVALID — aucune écriture
    }

    public function test_execute_force_accepte_sur_base_test(): void
    {
        // --force est toléré sur la base de test (garde-fou prod inactif).
        $src = $this->privateSource();
        $this->artisan('sources:reconcile-orphans', ['--execute' => true, '--force' => true])
            ->assertExitCode(0);
        $this->assertNotNull($src->fresh()->orphaned_at);
    }

    // ---- Garde-fou production (M13.1d) — connexion sqlite dédiée ----

    private function withOtherDb(callable $fn): void
    {
        $path = sys_get_temp_dir().'/reconcile_other_'.uniqid().'.sqlite';
        touch($path);
        $orig = config('database.default');
        config(['database.connections.reconcile_other' => [
            'driver' => 'sqlite', 'database' => $path, 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('reconcile_other');
        DB::statement('CREATE TABLE sources (id INTEGER PRIMARY KEY, is_catalogue INTEGER NOT NULL DEFAULT 0, orphaned_at TEXT NULL)');
        try {
            $fn($path);
        } finally {
            DB::setDefaultConnection($orig);
            DB::purge('reconcile_other');
            @unlink($path);
        }
    }

    public function test_execute_base_distincte_refuse_sans_force(): void
    {
        $this->withOtherDb(function (): void {
            $this->assertSame(2, Artisan::call(
                'sources:reconcile-orphans', ['--execute' => true]));
        });
    }

    public function test_force_avec_confirmation_exacte_autorise(): void
    {
        $this->withOtherDb(function (string $path): void {
            $this->artisan('sources:reconcile-orphans', ['--execute' => true, '--force' => true])
                ->expectsQuestion("Écriture demandée sur la base « $path » — saisissez son nom exact pour confirmer", $path)
                ->assertExitCode(0);
        });
    }

    public function test_force_nom_incorrect_refuse(): void
    {
        $this->withOtherDb(function (string $path): void {
            $this->artisan('sources:reconcile-orphans', ['--execute' => true, '--force' => true])
                ->expectsQuestion("Écriture demandée sur la base « $path » — saisissez son nom exact pour confirmer", 'mauvais_nom')
                ->assertExitCode(2);
        });
    }

    public function test_force_non_interactif_refuse(): void
    {
        $this->withOtherDb(function (): void {
            $this->assertSame(2, Artisan::call(
                'sources:reconcile-orphans', ['--execute' => true, '--force' => true]));
        });
    }

    public function test_source_id_cible_une_seule_source(): void
    {
        $a = $this->privateSource();
        $b = $this->privateSource();
        $this->artisan('sources:reconcile-orphans', ['--execute' => true, '--source-id' => $a->id])
            ->assertExitCode(0);
        $this->assertNotNull($a->fresh()->orphaned_at);
        $this->assertNull($b->fresh()->orphaned_at); // non touchée
    }
}
