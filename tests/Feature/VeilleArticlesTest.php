<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use App\Services\Articles\HtmlContentSanitizer;
use App\Services\Articles\ImageUrlPolicy;
use App\Services\Feed\IpResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VeilleArticlesTest extends TestCase
{
    use RefreshDatabase;

    private const CSP = "default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' https: http:; font-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

    private function userWithVeille(): array
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->create(['user_id' => $user->id]);

        return [$user, $veille];
    }

    /** Article associé à la veille (pivot user_id dérivé de la veille). */
    private function match(Veille $veille, array $article = [], ?string $keyword = 'mot-cle'): Article
    {
        $article = Article::factory()->create($article);
        ArticleVeille::factory()->create([
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => $keyword,
        ]);

        return $article;
    }

    private function showUrl(Veille $veille, Article $article, array $query = []): string
    {
        return route('veilles.articles.show', [$veille, $article] + $query);
    }

    // ---------- Authentification et isolation ----------

    public function test_invite_est_redirige_vers_login(): void
    {
        [, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->get(route('veilles.articles.index', $veille))->assertRedirect('/login');
        $this->get($this->showUrl($veille, $article))->assertRedirect('/login');
    }

    public function test_proprietaire_accede_aux_deux_pages(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, ['title' => 'Mon article']);

        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertSee('Mon article');
        $this->actingAs($user)->get($this->showUrl($veille, $article))
            ->assertOk()->assertSee('Mon article');
    }

    public function test_non_proprietaire_refuse(): void
    {
        [, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $intrus = User::factory()->create();

        $this->actingAs($intrus)->get(route('veilles.articles.index', $veille))->assertForbidden();
        $this->actingAs($intrus)->get($this->showUrl($veille, $article))->assertForbidden();
    }

    public function test_article_dune_autre_veille_inaccessible(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $autre = Veille::factory()->create(['user_id' => $user->id]);
        $article = $this->match($autre);   // associé à l'autre veille seulement

        $this->actingAs($user)->get($this->showUrl($veille, $article))->assertNotFound();
    }

    public function test_article_dune_veille_etrangere_inaccessible(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $autreUser = User::factory()->create();
        $veilleEtrangere = Veille::factory()->create(['user_id' => $autreUser->id]);
        $article = $this->match($veilleEtrangere);   // pivot user_id = autre user

        $this->actingAs($user)->get($this->showUrl($veille, $article))->assertNotFound();
    }

    public function test_article_dont_association_supprimee_inaccessible(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        ArticleVeille::where('veille_id', $veille->id)->where('article_id', $article->id)->delete();

        $this->actingAs($user)->get($this->showUrl($veille, $article))->assertNotFound();
    }

    public function test_article_hors_retention_inaccessible(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, [
            'title' => 'Ancien',
            'published_at' => now('UTC')->subMonths(7),
            'collected_at' => now('UTC')->subMonths(7),
        ]);

        $this->actingAs($user)->get($this->showUrl($veille, $article))->assertNotFound();
        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertDontSee('Ancien');
    }

    // ---------- Liste et filtres ----------

    public function test_liste_limitee_a_la_veille(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $this->match($veille, ['title' => 'Visible']);
        $autre = Veille::factory()->create(['user_id' => $user->id]);
        $this->match($autre, ['title' => 'Autre veille']);

        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertSee('Visible')->assertDontSee('Autre veille');
    }

    public function test_tri_stable_date_puis_id(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $same = now('UTC')->subDay();
        $this->match($veille, ['title' => 'Un', 'published_at' => $same, 'collected_at' => $same]);
        $this->match($veille, ['title' => 'Deux', 'published_at' => $same, 'collected_at' => $same]);
        $this->match($veille, ['title' => 'Trois plus recent', 'published_at' => now('UTC'), 'collected_at' => now('UTC')]);

        // Date effective DESC puis id DESC : Trois, Deux, Un.
        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertSeeInOrder(['Trois plus recent', 'Deux', 'Un']);
    }

    public function test_published_at_null_retombe_sur_collected_at(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $this->match($veille, ['title' => 'Avec date', 'published_at' => now('UTC')->subDays(2), 'collected_at' => now('UTC')]);
        $this->match($veille, ['title' => 'Sans date pub', 'published_at' => null, 'collected_at' => now('UTC')->subDay()]);

        // collected_at du « Sans date pub » (J-1) > published_at de « Avec date » (J-2).
        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertSeeInOrder(['Sans date pub', 'Avec date']);
    }

    public function test_pagination_de_20(): void
    {
        [$user, $veille] = $this->userWithVeille();
        for ($i = 1; $i <= 25; $i++) {
            $this->match($veille, ['title' => "Article {$i}", 'published_at' => now('UTC')->subMinutes($i)]);
        }

        $page1 = $this->actingAs($user)->get(route('veilles.articles.index', $veille));
        $page1->assertOk()->assertSee('Article 5')->assertDontSee('Article 24');
        $page2 = $this->actingAs($user)->get(route('veilles.articles.index', [$veille, 'page' => 2]));
        $page2->assertOk()->assertSee('Article 24')->assertDontSee('Article 5');
    }

    public function test_filtre_source(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $srcA = Source::factory()->create();
        $srcB = Source::factory()->create();
        $this->match($veille, ['title' => 'De A', 'source_id' => $srcA->id]);
        $this->match($veille, ['title' => 'De B', 'source_id' => $srcB->id]);

        $this->actingAs($user)->get(route('veilles.articles.index', [$veille, 'source_id' => $srcA->id]))
            ->assertOk()->assertSee('De A')->assertDontSee('De B');
    }

    public function test_filtre_mot_cle(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $this->match($veille, ['title' => 'Match laravel'], 'laravel');
        $this->match($veille, ['title' => 'Match php'], 'php');

        $this->actingAs($user)->get(route('veilles.articles.index', [$veille, 'keyword' => 'laravel']))
            ->assertOk()->assertSee('Match laravel')->assertDontSee('Match php');
    }

    public function test_combinaison_des_filtres(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $srcA = Source::factory()->create();
        $this->match($veille, ['title' => 'A + kw', 'source_id' => $srcA->id], 'laravel');
        $this->match($veille, ['title' => 'A autre kw', 'source_id' => $srcA->id], 'php');

        $this->actingAs($user)->get(route('veilles.articles.index', [
            $veille, 'source_id' => $srcA->id, 'keyword' => 'laravel',
        ]))->assertOk()->assertSee('A + kw')->assertDontSee('A autre kw');
    }

    public function test_options_de_filtre_limitees_au_perimetre(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $this->match($veille, ['title' => 'Ici'], 'php');
        $autre = Veille::factory()->create(['user_id' => $user->id]);
        $this->match($autre, ['title' => 'Ailleurs'], 'symfony');

        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertSee('value="php"', false)->assertDontSee('value="symfony"', false);
    }

    public function test_parametres_valides_propagues_malformes_ignores(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $this->match($veille, ['title' => 'Ok'], '0');   // mot-clé "0" légitime

        // "0" conservé, paramètres arbitraires absents des liens.
        $response = $this->actingAs($user)->get(route('veilles.articles.index', [
            $veille, 'keyword' => '0', 'evil' => '1',
        ]))->assertOk()->assertSee('Ok');
        $response->assertDontSee('evil=', false);

        // Malformés : aucune erreur, filtres inactifs.
        foreach (['source_id%5B%5D=1', 'keyword%5B%5D=x', 'page=abc', 'page=0', 'source_id=-1'] as $qs) {
            $this->actingAs($user)->get("/veilles/{$veille->id}/articles?{$qs}")->assertOk();
        }
    }

    // ---------- Lecture ----------

    public function test_contenu_assaini_affiche(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, [
            'content' => '<p>Texte <strong>gras</strong></p><script>evil_marker_1()</script><p onclick="evil_marker_3()">suite</p>',
        ]);

        $response = $this->actingAs($user)->get($this->showUrl($veille, $article))->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('<strong>gras</strong>', $content);
        // Les payloads (les noms génériques existent aussi dans le layout).
        $this->assertStringNotContainsString('evil_marker_1', $content);
        $this->assertStringNotContainsString('evil_marker_3', $content);
    }

    public function test_fallback_resume_puis_metadonnees(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $sansContenu = $this->match($veille, [
            'title' => 'Sans contenu', 'content' => null,
            'summary' => '<p>Résumé <em>italique</em></p>',
        ]);
        $sansRien = $this->match($veille, [
            'title' => 'Sans rien', 'content' => null, 'summary' => '<script>evil_marker_2()</script>',
        ]);

        $r1 = $this->actingAs($user)->get($this->showUrl($veille, $sansContenu))->assertOk();
        $this->assertStringContainsString('<em>italique</em>', $r1->getContent());

        $r2 = $this->actingAs($user)->get($this->showUrl($veille, $sansRien))->assertOk();
        $this->assertStringContainsString('pas disponible en lecture interne', $r2->getContent());
        $this->assertStringNotContainsString('evil_marker_2', $r2->getContent());
    }

    public function test_lien_original_nouvel_onglet_et_contenu_non_tronque(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $long = '<p>'.str_repeat('Phrase complète. ', 400).'</p>';
        $article = $this->match($veille, ['content' => $long, 'url' => 'https://exemple.fr/a']);

        $response = $this->actingAs($user)->get($this->showUrl($veille, $article))->assertOk();
        $response->assertSee('target="_blank"', false)->assertSee('noopener noreferrer', false)
            ->assertSee('https://exemple.fr/a', false);
        $this->assertStringContainsString(str_repeat('Phrase complète. ', 400), $response->getContent());
    }

    public function test_navigation_precedent_suivant_dans_le_perimetre(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $old = $this->match($veille, ['title' => 'Ancien', 'published_at' => now('UTC')->subDays(2)]);
        $mid = $this->match($veille, ['title' => 'Milieu', 'published_at' => now('UTC')->subDay()]);
        $new = $this->match($veille, ['title' => 'Recent', 'published_at' => now('UTC')]);

        $r = $this->actingAs($user)->get($this->showUrl($veille, $mid))->assertOk();
        $r->assertSee($this->showUrl($veille, $new), false)->assertSee($this->showUrl($veille, $old), false);

        $this->actingAs($user)->get($this->showUrl($veille, $new))
            ->assertSee($this->showUrl($veille, $mid), false)->assertDontSee('Plus récent', false);
    }

    public function test_navigation_respecte_les_filtres(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $a1 = $this->match($veille, ['published_at' => now('UTC')->subDays(2)], 'laravel');
        $hors = $this->match($veille, ['published_at' => now('UTC')->subDay()], 'php');
        $a2 = $this->match($veille, ['published_at' => now('UTC')], 'laravel');

        $r = $this->actingAs($user)->get($this->showUrl($veille, $a1, ['keyword' => 'laravel']));
        $r->assertSee($this->showUrl($veille, $a2, ['keyword' => 'laravel']), false)
            ->assertDontSee($this->showUrl($veille, $hors), false);
    }

    public function test_retour_liste_conserve_filtres_et_page(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $params = ['source_id' => (string) $article->source_id, 'keyword' => 'mot-cle', 'page' => '2'];

        // `{{ }}` échappe `&` en `&amp;` — comparer sur l'URL échappée.
        $expected = str_replace('&', '&amp;', route('veilles.articles.index', [$veille] + $params));
        $this->actingAs($user)->get($this->showUrl($veille, $article, $params))
            ->assertSee($expected, false);
    }

    public function test_images_validees_par_politique_dns_stub(): void
    {
        $this->app->instance(HtmlContentSanitizer::class, new HtmlContentSanitizer(
            imageUrls: new ImageUrlPolicy(resolver: new class implements IpResolverInterface
            {
                public function resolve(string $host): array
                {
                    return ['93.184.216.34'];
                }
            }),
        ));

        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, [
            'content' => '<p>t</p><img src="https://cdn.example.com/i.png">'
                .'<img src="http://10.0.0.1/private.png"><p>u</p>',
        ]);

        $response = $this->actingAs($user)->get($this->showUrl($veille, $article))->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $content);
        $this->assertStringContainsString('loading="lazy"', $content);
        $this->assertStringContainsString('cdn.example.com/i.png', $content);
        $this->assertStringNotContainsString('10.0.0.1', $content);
    }

    public function test_lien_original_masque_si_scheme_non_http(): void
    {
        [$user, $veille] = $this->userWithVeille();
        // L'URL stockée vient du flux : javascript: ne doit jamais être
        // rendu dans un href cliquable (audit M1.12.5).
        $article = $this->match($veille, ['url' => 'javascript:alert(1)', 'content' => '<p>t</p>']);

        $response = $this->actingAs($user)->get($this->showUrl($veille, $article))->assertOk();
        $this->assertStringNotContainsString('javascript:alert', $response->getContent());
        $this->assertStringNotContainsString('Ouvrir l\'article original', $response->getContent());
    }

    #[DataProvider('maliciousPayloads')]
    public function test_charges_malveillantes_neutralisees(string $payload, string $marker): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, ['content' => $payload]);

        $response = $this->actingAs($user)->get($this->showUrl($veille, $article))->assertOk();
        $this->assertStringNotContainsString($marker, $response->getContent());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function maliciousPayloads(): array
    {
        return [
            'svg onload' => ['<svg onload="evil_a()"></svg>', 'evil_a'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=javascript:evil_b()">', 'evil_b'],
            'base tag' => ['<base href="javascript:evil_c()">', 'evil_c'],
            'style tag' => ['<style>x{content:"evil_d"}</style>', 'evil_d'],
            'iframe src' => ['<iframe src="https://evil_e.test"></iframe>', 'evil_e'],
            'form' => ['<form action="https://evil_f.test"><input></form>', 'evil_f'],
            'noscript' => ['<noscript><script>evil_g()</script></noscript>', 'evil_g'],
            'object embed' => ['<object data="evil_h.swf"></object>', 'evil_h'],
            'event attr' => ['<p onmouseover="evil_i()">t</p>', 'evil_i'],
            'url entity-encodée' => ['<a href="jav&#x61;script:evil_j()">x</a>', 'evil_j'],
            'style attr' => ['<p style="behavior:url(evil_k)">t</p>', 'evil_k'],
            'target injecté' => ['<a href="https://x.fr" target="_blank" onclick="evil_l()">x</a>', 'evil_l'],
        ];
    }

    public function test_html_malforme_reequilibre(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, [
            'content' => '<p><b>ouvert sans fermer<a href="https://x.fr">lien',
        ]);

        $response = $this->actingAs($user)->get($this->showUrl($veille, $article))->assertOk();
        $this->assertStringContainsString('ouvert sans fermer', $response->getContent());
        $this->assertStringContainsString('href="https://x.fr"', $response->getContent());
    }

    // ---------- CSP ----------

    public function test_csp_presente_sur_liste_et_lecture(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertHeader('Content-Security-Policy', self::CSP);
        $this->actingAs($user)->get($this->showUrl($veille, $article))
            ->assertHeader('Content-Security-Policy', self::CSP);
    }
}
