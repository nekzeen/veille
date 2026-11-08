<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserExclusion;
use App\Models\Veille;
use App\Services\Articles\ArticleReadState;
use App\Services\ArticleVeilleWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M11.2 — filtre de lecture ?state=unread|all|read sur la page veille. */
class VeilleArticleStateTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->create(['user_id' => $user->id]);

        return [$user, $veille];
    }

    private function articleIn(Veille $v, string $url, string $title): Article
    {
        $guid = 'g-'.md5($url.$title);
        $a = Article::create([
            'source_id' => Source::factory()->create()->id,
            'guid' => $guid, 'dedup_key' => hash('sha256', $guid),
            'url' => $url, 'url_hash' => hash('sha256', $url),
            'title' => $title, 'summary' => 'R', 'content' => '<p>C</p>',
            'collected_at' => now('UTC'), 'published_at' => now('UTC'),
        ]);
        app(ArticleVeilleWriter::class)->record($v, $a->id, 'kw');

        return $a;
    }

    public function test_unread_dans_unread_et_all_pas_dans_read(): void
    {
        [$u, $v] = $this->fixture();
        $this->articleIn($v, 'https://ex.fr/1', 'ArtNonLu');

        $this->actingAs($u)->get("/veilles/{$v->id}/articles")->assertSee('ArtNonLu');
        $this->actingAs($u)->get("/veilles/{$v->id}/articles?state=all")->assertSee('ArtNonLu');
        $this->actingAs($u)->get("/veilles/{$v->id}/articles?state=read")->assertDontSee('ArtNonLu');
    }

    public function test_lu_dans_read_et_all_pas_dans_unread(): void
    {
        [$u, $v] = $this->fixture();
        $a = $this->articleIn($v, 'https://ex.fr/1', 'ArtLu');
        app(ArticleReadState::class)->markRead($u, $a);

        $this->actingAs($u)->get("/veilles/{$v->id}/articles?state=read")->assertSee('ArtLu');
        $this->actingAs($u)->get("/veilles/{$v->id}/articles?state=all")->assertSee('ArtLu');
        $this->actingAs($u)->get("/veilles/{$v->id}/articles")->assertDontSee('ArtLu');
    }

    public function test_statut_global_partage_entre_veilles(): void
    {
        [$u, $v1] = $this->fixture();
        $v2 = Veille::factory()->create(['user_id' => $u->id]);
        $a = $this->articleIn($v1, 'https://ex.fr/1', 'Partage');
        app(ArticleVeilleWriter::class)->record($v2, $a->id, 'kw');
        app(ArticleReadState::class)->markRead($u, $a);

        $this->actingAs($u)->get("/veilles/{$v1->id}/articles")->assertDontSee('Partage');
        $this->actingAs($u)->get("/veilles/{$v2->id}/articles")->assertDontSee('Partage');
        $this->actingAs($u)->get("/veilles/{$v2->id}/articles?state=read")->assertSee('Partage');
    }

    public function test_statut_autre_utilisateur_ininfluent(): void
    {
        [$u, $v1] = $this->fixture();
        [$other, $v2] = $this->fixture();
        $a = $this->articleIn($v1, 'https://ex.fr/1', 'Commun');
        app(ArticleVeilleWriter::class)->record($v2, $a->id, 'kw');
        app(ArticleReadState::class)->markRead($other, $a);

        $this->actingAs($u)->get("/veilles/{$v1->id}/articles")->assertSee('Commun');
    }

    public function test_exclusions_m9_restent_prioritaires(): void
    {
        [$u, $v] = $this->fixture();
        UserExclusion::create(['user_id' => $u->id, 'kind' => 'url', 'pattern' => 'indeed.com']);
        $this->articleIn($v, 'https://indeed.com/x', 'MasqueToujours');
        $this->articleIn($v, 'https://ex.fr/ok', 'Visible');

        foreach (['unread', 'all', 'read'] as $s) {
            $r = $this->actingAs($u)->get("/veilles/{$v->id}/articles?state=$s")->assertOk();
            $r->assertDontSee('MasqueToujours'); // jamais visible, même dans « Lus »
        }
        $this->actingAs($u)->get("/veilles/{$v->id}/articles")->assertSee('Visible');
    }

    public function test_state_invalide_retombe_sur_unread(): void
    {
        [$u, $v] = $this->fixture();
        $this->articleIn($v, 'https://ex.fr/1', 'ArtDefaut');
        $a = $this->articleIn($v, 'https://ex.fr/2', 'LuDefaut');
        app(ArticleReadState::class)->markRead($u, $a);

        $r = $this->actingAs($u)->get("/veilles/{$v->id}/articles?state=bidon")->assertOk();
        $r->assertSee('ArtDefaut')->assertDontSee('LuDefaut');
    }

    public function test_filtres_source_et_state_cumules(): void
    {
        [$u, $v] = $this->fixture();
        $a = $this->articleIn($v, 'https://ex.fr/1', 'SourceA');
        $r = $this->actingAs($u)->get(
            "/veilles/{$v->id}/articles?state=unread&source_id={$a->source_id}",
        );
        $r->assertOk()->assertSee('SourceA');
        $r = $this->actingAs($u)->get(
            "/veilles/{$v->id}/articles?state=unread&source_id=99999",
        );
        $r->assertDontSee('SourceA');
    }

    public function test_onglets_visibles_et_actif_marque(): void
    {
        [$u, $v] = $this->fixture();
        $r = $this->actingAs($u)->get("/veilles/{$v->id}/articles?state=read")->assertOk();
        $html = $r->getContent();
        $this->assertStringContainsString('Non lus', $html);
        $this->assertStringContainsString('Tous', $html);
        $this->assertStringContainsString('Lus', $html);
        $this->assertStringContainsString('state=read', $html);
    }

    public function test_tout_marquer_lu_perimetre_et_global(): void
    {
        [$u, $v1] = $this->fixture();
        $v2 = Veille::factory()->create(['user_id' => $u->id]);
        $a1 = $this->articleIn($v1, 'https://ex.fr/a1', 'A1');
        $a2 = $this->articleIn($v1, 'https://ex.fr/a2', 'A2');
        $a3 = $this->articleIn($v2, 'https://ex.fr/a3', 'A3AutreVeille');
        // A2 aussi dans v2 -> statut global : lu dans les deux.
        app(ArticleVeilleWriter::class)->record($v2, $a2->id, 'kw');

        $r = $this->actingAs($u)->post("/veilles/{$v1->id}/articles/read-all")->assertRedirect();
        $rs = app(ArticleReadState::class);
        $this->assertTrue($rs->isRead($u, $a1));
        $this->assertTrue($rs->isRead($u, $a2));
        $this->assertFalse($rs->isRead($u, $a3)); // autre veille épargnée

        // Idempotent : second passage = 0 écriture utile.
        $r2 = $this->actingAs($u)->post("/veilles/{$v1->id}/articles/read-all");
        $this->assertStringContainsString('0 article', session('status'));
    }

    public function test_tout_marquer_lu_respecte_exclusions_et_isolation(): void
    {
        [$u, $v] = $this->fixture();
        UserExclusion::create(['user_id' => $u->id, 'kind' => 'url', 'pattern' => 'indeed.com']);
        $masque = $this->articleIn($v, 'https://indeed.com/x', 'Masque');
        $ok = $this->articleIn($v, 'https://ex.fr/ok', 'Ok');

        // Autre utilisateur : sa veille est 404, son statut intact.
        [$other] = [User::factory()->create()];
        $vOther = Veille::factory()->create(['user_id' => $other->id]);
        $aOther = $this->articleIn($vOther, 'https://ex.fr/o', 'Other');
        $this->actingAs($u)->post("/veilles/{$vOther->id}/articles/read-all")->assertForbidden();

        $this->actingAs($u)->post("/veilles/{$v->id}/articles/read-all")->assertRedirect();
        $rs = app(ArticleReadState::class);
        $this->assertTrue($rs->isRead($u, $ok));
        $this->assertFalse($rs->isRead($u, $masque));   // M9 : non traité
        $this->assertFalse($rs->isRead($other, $aOther)); // autre user intact
    }

    public function test_tout_marquer_lu_preserve_donnees_perso_et_retention(): void
    {
        [$u, $v] = $this->fixture();
        $a = $this->articleIn($v, 'https://ex.fr/1', 'AvecNote');
        ArticleUser::create([
            'user_id' => $u->id, 'article_id' => $a->id,
            'is_read' => false, 'is_favorite' => true, 'note' => 'ma note',
        ]);
        // Article hors rétention (> 6 mois) : non traité.
        $old = Article::create([
            'source_id' => Source::factory()->create()->id,
            'guid' => 'g-old', 'dedup_key' => hash('sha256', 'g-old'),
            'url' => 'https://ex.fr/old', 'url_hash' => hash('sha256', 'https://ex.fr/old'),
            'title' => 'Old', 'summary' => 'R', 'content' => '<p>C</p>',
            'collected_at' => now('UTC')->subMonths(8), 'published_at' => now('UTC')->subMonths(8),
        ]);
        ArticleVeille::create(['veille_id' => $v->id, 'article_id' => $old->id, 'user_id' => $u->id, 'matched_keyword' => 'k']);

        $this->actingAs($u)->post("/veilles/{$v->id}/articles/read-all")->assertRedirect();
        $row = ArticleUser::where('article_id', $a->id)->where('user_id', $u->id)->first();
        $this->assertTrue((bool) $row->is_read);
        $this->assertTrue((bool) $row->is_favorite);
        $this->assertSame('ma note', $row->note);
        $this->assertFalse(app(ArticleReadState::class)->isRead($u, $old));
    }
}
