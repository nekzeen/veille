<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use App\Services\ArticleVeilleWriter;
use App\Services\SourceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M13.1a — recalcul centralisé de `orphaned_at` sur tous les chemins. */
class SourceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function privateSource(): Source
    {
        return Source::factory()->create(['is_catalogue' => false]);
    }

    private function subscribeTo(int $uid, int $sid): int
    {
        return DB::table('user_sources')->insertGetId(['user_id' => $uid, 'source_id' => $sid,
            'created_at' => now()]);
    }

    public function test_suppression_compte_rend_orpheline_source_privee(): void
    {
        $u = User::factory()->create(['password' => 'motdepasse-fort-42']);
        $src = $this->privateSource();
        $this->subscribeTo($u->id, $src->id);

        $this->actingAs($u)->delete('/profile', ['password' => 'motdepasse-fort-42']);

        $this->assertNotNull($src->fresh()->orphaned_at);
    }

    public function test_suppression_compte_preserve_source_partagee(): void
    {
        $u1 = User::factory()->create(['password' => 'motdepasse-fort-42']);
        $u2 = User::factory()->create();
        $src = $this->privateSource();
        $this->subscribeTo($u1->id, $src->id);
        $this->subscribeTo($u2->id, $src->id);

        $this->actingAs($u1)->delete('/profile', ['password' => 'motdepasse-fort-42']);

        $this->assertNull($src->fresh()->orphaned_at); // u2 l'utilise encore
    }

    public function test_suppression_veille_rend_orpheline_source_sans_autre_lien(): void
    {
        $u = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $src = $this->privateSource();
        DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $src->id, 'user_id' => $u->id]);

        $this->actingAs($u)->delete("/veilles/{$v->id}");

        $this->assertNotNull($src->fresh()->orphaned_at);
    }

    public function test_suppression_veille_preserve_source_utilisee_ailleurs(): void
    {
        $u = User::factory()->create();
        $v1 = Veille::factory()->create(['user_id' => $u->id]);
        $v2 = Veille::factory()->create(['user_id' => $u->id]);
        $src = $this->privateSource();
        foreach ([$v1, $v2] as $v) {
            DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $src->id, 'user_id' => $u->id]);
        }

        $this->actingAs($u)->delete("/veilles/{$v1->id}");

        $this->assertNull($src->fresh()->orphaned_at); // encore dans v2
    }

    public function test_desabonnement_conserve_le_comportement_existant(): void
    {
        $u = User::factory()->create();
        $src = $this->privateSource();
        $us = $this->subscribeTo($u->id, $src->id);

        $this->actingAs($u)->delete("/sources/{$us}");

        $this->assertNotNull($src->fresh()->orphaned_at);
    }

    public function test_reliaison_annule_orphaned_at(): void
    {
        // Le délai est annulé si la source est réutilisée : recalcul
        // après restauration d'un lien (abonnement) → orphaned_at NULL.
        // NB : subscribe() exige aujourd'hui une souscription existante
        // pour les sources privées — la re-liaison directe est testée.
        $u = User::factory()->create();
        $src = $this->privateSource();
        $us = $this->subscribeTo($u->id, $src->id);
        $this->actingAs($u)->delete("/sources/{$us}");
        $this->assertNotNull($src->fresh()->orphaned_at);

        $this->subscribeTo($u->id, $src->id);
        app(SourceLifecycleService::class)->reevaluate($src);

        $this->assertNull($src->fresh()->orphaned_at);
    }

    public function test_source_catalogue_jamais_orpheline(): void
    {
        $src = Source::factory()->create(['is_catalogue' => true, 'orphaned_at' => null]);
        app(SourceLifecycleService::class)->reevaluate($src);
        $this->assertNull($src->fresh()->orphaned_at);
    }

    public function test_detach_article_sans_suppression_ne_rend_pas_orpheline(): void
    {
        // Retirer article_veille conserve l'article → source non orpheline.
        $u = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $src = $this->privateSource();
        $a = Article::factory()->create(['source_id' => $src->id]);
        ArticleVeille::create(['veille_id' => $v->id, 'article_id' => $a->id, 'user_id' => $u->id, 'matched_keyword' => 'k']);
        DB::table('source_veille')->insert(['veille_id' => $v->id, 'source_id' => $src->id, 'user_id' => $u->id]);

        app(ArticleVeilleWriter::class)->deleteFor($v, [$a->id]);

        $this->assertNull($src->fresh()->orphaned_at); // article existe + veille liée
    }

    public function test_dernier_article_supprime_rend_orpheline(): void
    {
        $u = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $src = $this->privateSource();
        $a = Article::factory()->create(['source_id' => $src->id, 'published_at' => null,
            'collected_at' => now()->subMonths(7)]);
        ArticleVeille::create(['veille_id' => $v->id, 'article_id' => $a->id, 'user_id' => $u->id, 'matched_keyword' => 'k']);
        // Détachement + last_detached_at > 7 j → éligible purge B
        app(ArticleVeilleWriter::class)->deleteFor($v, [$a->id]);
        Article::where('id', $a->id)->update(['last_detached_at' => now()->subDays(8)]);

        $this->artisan('articles:purge', ['--execute' => true])->assertExitCode(0);

        $this->assertNotNull($src->fresh()->orphaned_at); // plus d'article ni de lien
    }

    public function test_plusieurs_articles_orpheline_apres_le_dernier(): void
    {
        $u = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $src = $this->privateSource();
        $a1 = Article::factory()->create(['source_id' => $src->id, 'published_at' => null, 'collected_at' => now()->subMonths(7)]);
        $a2 = Article::factory()->create(['source_id' => $src->id, 'published_at' => null, 'collected_at' => now()->subMonths(7)]);
        ArticleVeille::create(['veille_id' => $v->id, 'article_id' => $a1->id, 'user_id' => $u->id, 'matched_keyword' => 'k']);
        ArticleVeille::create(['veille_id' => $v->id, 'article_id' => $a2->id, 'user_id' => $u->id, 'matched_keyword' => 'k']);
        $writer = app(ArticleVeilleWriter::class);

        $writer->deleteFor($v, [$a1->id]);
        Article::where('id', $a1->id)->update(['last_detached_at' => now()->subDays(8)]);
        $this->artisan('articles:purge', ['--execute' => true])->assertExitCode(0);
        $this->assertNull($src->fresh()->orphaned_at); // a2 existe encore

        $writer->deleteFor($v, [$a2->id]);
        Article::where('id', $a2->id)->update(['last_detached_at' => now()->subDays(8)]);
        $this->artisan('articles:purge', ['--execute' => true])->assertExitCode(0);
        $this->assertNotNull($src->fresh()->orphaned_at); // dernier parti
    }
}
