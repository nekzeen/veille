<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\Source;
use App\Models\User;
use App\Models\UserExclusion;
use App\Models\Veille;
use App\Models\VeilleShare;
use App\Services\ArticleVeilleWriter;
use App\Services\Shares\ShareTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M10.4 — consultation publique : périmètre, jetons, confidentialité. */
class PublicShareTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $global = false): array
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->create(['user_id' => $user->id]);
        $share = VeilleShare::create([
            'user_id' => $user->id,
            'veille_id' => $global ? null : $veille->id,
            'scope' => $global ? 'all' : 'single',
            'token_hash' => 'x',
        ]);
        $token = app(ShareTokenService::class)->issue($share);

        return [$user, $veille, $share, $token];
    }

    private function articleIn(Veille $veille, string $url, string $title = 'Titre'): Article
    {
        $guid = 'g-'.md5($url);
        $a = Article::create([
            'source_id' => Source::factory()->create()->id,
            'guid' => $guid, 'dedup_key' => hash('sha256', $guid),
            'url' => $url, 'url_hash' => hash('sha256', $url),
            'title' => $title, 'summary' => 'Résumé', 'content' => '<p>Corps</p>',
            'collected_at' => now('UTC'),
        ]);
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw-secret');

        return $a;
    }

    public function test_jeton_valide_liste_et_detail(): void
    {
        [, $veille, , $token] = $this->fixture();
        $a = $this->articleIn($veille, 'https://ex.fr/1', 'Article public');

        $this->get("/shared/$token")->assertOk()->assertSee('Article public');
        $this->get("/shared/$token/veilles/{$veille->id}")->assertOk();
        $this->get("/shared/$token/articles/{$a->id}")
            ->assertOk()->assertSee('Article public')->assertSee('Corps');
    }

    public function test_jeton_inconnu_expire_revoque_anciens_404(): void
    {
        [$u, $veille, $share, $token] = $this->fixture();
        $this->get('/shared/jeton-inconnu')->assertNotFound();

        $share->update(['expires_at' => now('UTC')->subMinute()]);
        $this->get("/shared/$token")->assertNotFound();
        $share->update(['expires_at' => null]);

        app(ShareTokenService::class)->revoke($share->fresh());
        $this->get("/shared/$token")->assertNotFound();

        $share->update(['revoked_at' => null, 'is_active' => true]);
        app(ShareTokenService::class)->rotate($share->fresh());
        $this->get("/shared/$token")->assertNotFound(); // ancien mort
    }

    public function test_single_ne_voit_pas_autre_veille_ni_article(): void
    {
        [$u, $veille, , $token] = $this->fixture();
        $other = Veille::factory()->create(['user_id' => $u->id]);
        $a = $this->articleIn($other, 'https://ex.fr/2', 'Hors perimetre');

        $this->get("/shared/$token/veilles/{$other->id}")->assertNotFound();
        $this->get("/shared/$token/articles/{$a->id}")->assertNotFound();
    }

    public function test_global_dynamique_nouvelle_et_supprimee(): void
    {
        [$u, , , $token] = $this->fixture(global: true);
        $new = Veille::factory()->create(['user_id' => $u->id, 'name' => 'NouvelleVeille']);
        $this->get("/shared/$token")->assertOk()->assertSee('NouvelleVeille');

        $new->delete();
        $this->get("/shared/$token")->assertOk()->assertDontSee('NouvelleVeille');
        $this->get("/shared/$token/veilles/{$new->id}")->assertNotFound();
    }

    public function test_exclusions_m9_respectees_en_public(): void
    {
        [$u, $veille, , $token] = $this->fixture();
        UserExclusion::create(['user_id' => $u->id, 'kind' => 'url', 'pattern' => 'indeed.com']);
        $this->articleIn($veille, 'https://indeed.com/cache', 'MasquePerso');
        $this->articleIn($veille, 'https://ex.fr/ok', 'VisibleOk');

        $r = $this->get("/shared/$token")->assertOk();
        $r->assertSee('VisibleOk')->assertDontSee('MasquePerso');
    }

    public function test_aucune_donnee_privee_dans_html(): void
    {
        [$u, $veille, , $token] = $this->fixture();
        $a = $this->articleIn($veille, 'https://ex.fr/1', 'Clean');
        ArticleUser::create([
            'user_id' => $u->id, 'article_id' => $a->id,
            'is_read' => true, 'is_favorite' => true, 'note' => 'note secrete',
        ]);

        $html = $this->get("/shared/$token/articles/{$a->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('kw-secret', $html); // matched_keyword
        $this->assertStringNotContainsString('note secrete', $html);
        $this->assertStringNotContainsString($u->email, $html);
        $this->assertStringNotContainsString('is_favorite', $html);
    }

    public function test_isolation_deux_proprietaires(): void
    {
        [$u, $veille, , $token] = $this->fixture();
        $other = User::factory()->create();
        $v2 = Veille::factory()->create(['user_id' => $other->id]);
        $a2 = $this->articleIn($v2, 'https://ex.fr/etranger', 'ArticleEtranger');

        $this->get("/shared/$token/veilles/{$v2->id}")->assertNotFound();
        $this->get("/shared/$token/articles/{$a2->id}")->assertNotFound();
    }

    public function test_headers_publics_et_pas_de_session_auth(): void
    {
        [, , , $token] = $this->fixture();
        $r = $this->get("/shared/$token");
        $r->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $r->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertGuest();

        // Les 404 (jeton invalide) portent aussi les headers de sécurité.
        $r = $this->get('/shared/jeton-inconnu-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
        $r->assertNotFound();
        $r->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_xss_contenu_article_sanitise(): void
    {
        [, $veille, , $token] = $this->fixture();
        $guid = 'g-xss';
        $a = Article::create([
            'source_id' => Source::factory()->create()->id,
            'guid' => $guid, 'dedup_key' => hash('sha256', $guid),
            'url' => 'https://ex.fr/x', 'url_hash' => hash('sha256', 'https://ex.fr/x'),
            'title' => '<b>gras</b><script>alert(1)</script>',
            'summary' => 'ok', 'content' => '<p onclick="x()">Corps</p><script>evil()</script>',
            'collected_at' => now('UTC'),
        ]);
        app(ArticleVeilleWriter::class)->record($veille, $a->id, 'kw');

        $html = $this->get("/shared/$token/articles/{$a->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick=', $html);
    }

    public function test_throttle_apres_60_requetes(): void
    {
        [, , , $token] = $this->fixture();
        for ($i = 0; $i < 60; $i++) {
            $this->get("/shared/$token");
        }
        $this->get("/shared/$token")->assertStatus(429);
    }

    public function test_visiteur_ne_peut_rien_modifier(): void
    {
        [, , , $token] = $this->fixture();
        $this->post("/shared/$token")->assertStatus(405);
        $this->delete("/shared/$token")->assertStatus(405);
    }
}
