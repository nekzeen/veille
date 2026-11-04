<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserExclusion;
use App\Services\Exclusions\ExclusionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M9.2 — ExclusionMatcher : justifications par règle active. */
class ExclusionMatcherTest extends TestCase
{
    use RefreshDatabase;

    private ExclusionMatcher $matcher;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = app(ExclusionMatcher::class);
        $this->user = User::factory()->create();
    }

    private function rule(string $kind, string $pattern, bool $active = true): UserExclusion
    {
        return UserExclusion::create([
            'user_id' => $this->user->id,
            'kind' => $kind,
            'pattern' => $pattern,
            'is_active' => $active,
        ]);
    }

    private function ids(?string $url = 'https://ex.fr/a', string $haystack = 'Titre article'): array
    {
        return $this->matcher->justifications($this->user->id, $url, $haystack);
    }

    public function test_domaine_entier_exact_pas_suffixe(): void
    {
        $r = $this->rule('url', 'indeed.com');
        $this->assertSame([$r->id], $this->ids('https://www.indeed.com/offre/1'));
        $this->assertSame([], $this->ids('https://notindeed.com/x'));
        $this->assertSame([], $this->ids('https://fr.indeed.com/x')); // apex seul
    }

    public function test_sous_domaines_seulement(): void
    {
        $r = $this->rule('url', '*.indeed.com');
        $this->assertSame([$r->id], $this->ids('https://fr.indeed.com/j'));
        // www est canonisé en apex (§4.B.2) → pas un sous-domaine.
        $this->assertSame([], $this->ids('https://www.indeed.com/j'));
        $this->assertSame([], $this->ids('https://indeed.com/j'));
    }

    public function test_url_exacte_canonisee(): void
    {
        $r = $this->rule('url', 'https://ex.fr/offre/42?utm_campaign=x');
        $this->assertSame([$r->id], $this->ids('HTTPS://WWW.EX.FR/offre/42?utm_source=google#frag'));
        $this->assertSame([], $this->ids('https://ex.fr/offre/43'));
    }

    public function test_prefixe_de_chemin_borne_segment(): void
    {
        $r = $this->rule('url', '/freelance/');
        $this->assertSame([$r->id], $this->ids('https://ex.fr/freelance/valerie'));
        $this->assertSame([$r->id], $this->ids('https://ex.fr/freelance'));
        $this->assertSame([], $this->ids('https://ex.fr/freelancer/bob'));
    }

    public function test_redirection_google_resolue_avant_comparaison(): void
    {
        $r = $this->rule('url', 'lesbonsfreelances.com');
        $this->assertSame([$r->id], $this->ids(
            'https://www.google.com/url?url='.rawurlencode('https://www.lesbonsfreelances.com/freelance/p7'),
        ));
    }

    public function test_keyword_expression_casse_accents(): void
    {
        $r = $this->rule('keyword', 'Contenu Sponsorisé');
        $this->assertSame([$r->id], $this->ids('https://ex.fr/a', 'Un <b>contenu sponsorisé</b> exclusif'));
        $this->assertSame([$r->id], $this->ids('https://ex.fr/a', 'contenu sponsorise sans accents'));
    }

    public function test_keyword_bornes_de_mots(): void
    {
        $r = $this->rule('keyword', 'art');
        $this->assertSame([], $this->ids('https://ex.fr/a', 'département partenaire'));
        $this->assertSame([$r->id], $this->ids('https://ex.fr/a', "l'art et la manière"));
    }

    public function test_plusieurs_regles_meme_article(): void
    {
        $a = $this->rule('url', 'indeed.com');
        $b = $this->rule('keyword', 'emploi');
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $this->ids('https://indeed.com/x', 'une offre emploi'),
        );
    }

    public function test_regle_inactive_jamais_retournee(): void
    {
        $this->rule('url', 'indeed.com', active: false);
        $this->assertSame([], $this->ids('https://indeed.com/x'));
    }

    public function test_isolation_regles_dun_autre_utilisateur(): void
    {
        UserExclusion::create([
            'user_id' => User::factory()->create()->id,
            'kind' => 'url', 'pattern' => 'indeed.com',
        ]);
        $this->assertSame([], $this->ids('https://indeed.com/x'));
    }

    public function test_url_invalide_aucune_regle_url(): void
    {
        $this->rule('url', 'indeed.com');
        $this->assertSame([], $this->ids('ht!tp://invalide'));
        $this->assertSame([], $this->ids(null));
    }

    public function test_aucune_correspondance(): void
    {
        $this->rule('url', 'indeed.com');
        $this->rule('keyword', 'emploi');
        $this->assertSame([], $this->ids('https://ex.fr/ok', 'sujet neutre'));
    }
}
