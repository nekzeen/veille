<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use App\Services\KeywordMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordMatcherTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Utilisateur abonné à la source + veille contenant la source +
     * mots-clés — périmètre nominal d'une correspondance.
     *
     * @param  list<string>  $terms
     * @return array{0: User, 1: Veille}
     */
    private function subscribedVeille(Source $source, array $terms, bool $active = true): array
    {
        $user = User::factory()->create();
        UserSource::factory()->for($user)->for($source)->create();

        $veille = Veille::factory()->for($user)->create(['is_active' => $active]);
        $veille->sources()->attach($source->id);

        foreach ($terms as $term) {
            VeilleKeyword::factory()->for($veille)->create(['term' => $term]);
        }

        return [$user, $veille];
    }

    private function matcher(): KeywordMatcher
    {
        return app(KeywordMatcher::class);
    }

    public function test_keyword_in_title_creates_match(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['formation']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Formation professionnelle continue',
            'summary' => 'Résumé sans rapport',
        ]);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(1, $result->examined);
        $this->assertSame(1, $result->matched);
        $this->assertSame(1, $result->created);
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'user_id' => $veille->user_id,
            'matched_keyword' => 'formation',
        ]);
    }

    public function test_keyword_in_summary_creates_match(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['formateur']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Titre sans rapport',
            'summary' => 'Devenir formateur en trois étapes',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => 'formateur',
        ]);
    }

    public function test_absent_keyword_creates_no_match(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['numérique']);
        $article = Article::factory()->for($source)->create([
            'title' => 'La transformation digitale des PME',
            'summary' => 'Analyse de fond',
        ]);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(1, $result->examined);
        $this->assertSame(0, $result->matched);
        $this->assertSame(0, $result->created);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
    }

    public function test_or_logic_one_matching_keyword_suffices(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['symfony', 'laravel', 'doctrine']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Laravel 12 est disponible',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => 'laravel',
        ]);
    }

    public function test_earliest_created_keyword_wins(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, []);
        VeilleKeyword::factory()->for($veille)->create([
            'term' => 'laravel',
            'created_at' => '2026-02-01 00:00:00',
        ]);
        VeilleKeyword::factory()->for($veille)->create([
            'term' => 'php',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $article = Article::factory()->for($source)->create([
            'title' => 'Laravel et PHP en pratique',
        ]);

        $this->matcher()->matchArticle($article);

        // 'php' est postérieur en id mais antérieur en created_at.
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => 'php',
        ]);
    }

    public function test_same_created_at_falls_back_to_lowest_id(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, []);
        VeilleKeyword::factory()->for($veille)->create([
            'term' => 'laravel',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        VeilleKeyword::factory()->for($veille)->create([
            'term' => 'php',
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $article = Article::factory()->for($source)->create([
            'title' => 'Laravel et PHP en pratique',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => 'laravel',
        ]);
    }

    public function test_matching_is_case_insensitive(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['laravel']);
        $article = Article::factory()->for($source)->create([
            'title' => 'LARAVEL Framework',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
        ]);
    }

    public function test_matching_is_accent_insensitive_both_ways(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['education']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Éducation des adultes',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => 'education',
        ]);
    }

    public function test_matched_keyword_keeps_the_stored_term_text(): void
    {
        $source = Source::factory()->create();
        // 'Numérique' est stocké normalisé §3.5 (trim + minuscules)
        // avec ses accents : matched_keyword conserve cette forme.
        [, $veille] = $this->subscribedVeille($source, ['  Numérique  ']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Le NUMERIQUE en entreprise',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => 'numérique',
        ]);
    }

    public function test_blank_keyword_never_matches(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['   ']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Un titre avec des espaces',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
    }

    public function test_null_summary_still_matches_title(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['laravel']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Laravel 12',
            'summary' => null,
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
        ]);
    }

    public function test_html_tags_in_summary_are_stripped_for_matching(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['formation']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Titre neutre',
            'summary' => '<p>La <b>formation</b> continue</p>',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
        ]);
    }

    public function test_html_entities_are_decoded_for_matching(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['café']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Titre neutre',
            'summary' => 'Le caf&eacute; du commerce',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
        ]);
    }

    public function test_multiword_keyword_matches_across_html_boundaries(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['formation continue']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Titre neutre',
            'summary' => '<p>La formation</p><p>continue en entreprise</p>',
        ]);

        $this->matcher()->matchArticle($article);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => 'formation continue',
        ]);
    }

    public function test_whitespace_variants_are_equivalent(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['laravel 12']);

        foreach (["Laravel\t12", "Laravel\n12", 'Laravel&nbsp;12'] as $title) {
            $article = Article::factory()->for($source)->create(['title' => $title]);

            $this->matcher()->matchArticle($article);

            $this->assertDatabaseHas('article_veille', [
                'veille_id' => $veille->id,
                'article_id' => $article->id,
            ]);
        }
    }

    public function test_article_content_is_not_analyzed(): void
    {
        $source = Source::factory()->create();
        [, $veille] = $this->subscribedVeille($source, ['laravel']);
        $article = Article::factory()->for($source)->create([
            'title' => 'Titre sans rapport',
            'summary' => 'Résumé sans rapport',
            'content' => '<p>Tout sur Laravel dans le corps.</p>',
        ]);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(0, $result->created);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
    }
}
