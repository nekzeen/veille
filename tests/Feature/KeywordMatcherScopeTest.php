<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use App\Services\KeywordMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordMatcherScopeTest extends TestCase
{
    use RefreshDatabase;

    private function matcher(): KeywordMatcher
    {
        return app(KeywordMatcher::class);
    }

    /**
     * Veille d'un utilisateur, contenant la source, avec les mots-clés
     * fournis. L'abonnement (user_sources) est optionnel selon le cas.
     *
     * @param  list<string>  $terms
     */
    private function veilleOf(User $user, Source $source, array $terms, bool $active = true): Veille
    {
        $veille = Veille::factory()->for($user)->create(['is_active' => $active]);
        $veille->sources()->attach($source->id);

        foreach ($terms as $term) {
            VeilleKeyword::factory()->for($veille)->create(['term' => $term]);
        }

        return $veille;
    }

    public function test_inactive_veille_receives_no_match(): void
    {
        $source = Source::factory()->create();
        $user = User::factory()->create();
        UserSource::factory()->for($user)->for($source)->create();
        $veille = $this->veilleOf($user, $source, ['laravel'], active: false);
        $article = Article::factory()->for($source)->create(['title' => 'Laravel 12']);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(0, $result->created);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
        $this->assertFalse($veille->fresh()->is_active); // jamais réactivée
    }

    public function test_veille_without_the_articles_source_is_ignored(): void
    {
        $source = Source::factory()->create();
        $otherSource = Source::factory()->create();
        $user = User::factory()->create();
        UserSource::factory()->for($user)->for($source)->create();
        $veille = $this->veilleOf($user, $otherSource, ['laravel']);
        $article = Article::factory()->for($source)->create(['title' => 'Laravel 12']);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(0, $result->created);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
    }

    public function test_veille_of_non_subscriber_is_ignored(): void
    {
        $source = Source::factory()->create();
        $user = User::factory()->create();
        // La veille référence la source mais l'utilisateur n'a pas de
        // user_sources — hors périmètre (souscription requise).
        $veille = $this->veilleOf($user, $source, ['laravel']);
        $article = Article::factory()->for($source)->create(['title' => 'Laravel 12']);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(0, $result->examined);
        $this->assertSame(0, $result->created);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
    }

    public function test_only_subscriber_veilles_are_matched(): void
    {
        $source = Source::factory()->create();
        $otherSource = Source::factory()->create();

        // A : abonné à la source de l'article.
        $userA = User::factory()->create();
        UserSource::factory()->for($userA)->for($source)->create();
        $veilleA = $this->veilleOf($userA, $source, ['laravel']);

        // B : abonné à une autre source uniquement ; sa veille
        // référence malgré tout la source de l'article.
        $userB = User::factory()->create();
        UserSource::factory()->for($userB)->for($otherSource)->create();
        $veilleB = $this->veilleOf($userB, $source, ['laravel']);

        $article = Article::factory()->for($source)->create(['title' => 'Laravel 12']);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(1, $result->created);
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veilleA->id,
            'article_id' => $article->id,
            'user_id' => $userA->id,
        ]);
        // Jamais de correspondance pour une veille d'un non-abonné.
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veilleB->id]);
    }

    public function test_catalogue_source_matches_each_subscriber_privately(): void
    {
        $source = Source::factory()->catalogue()->create();

        $userA = User::factory()->create();
        UserSource::factory()->for($userA)->for($source)->create();
        $veilleA = $this->veilleOf($userA, $source, ['laravel']);

        $userB = User::factory()->create();
        UserSource::factory()->for($userB)->for($source)->create();
        $veilleB = $this->veilleOf($userB, $source, ['php']);

        $article = Article::factory()->for($source)->create(['title' => 'Laravel et PHP']);

        $result = $this->matcher()->matchArticle($article);

        // Deux correspondances privées, chacune au nom de son
        // propriétaire — aucune donnée partagée entre utilisateurs.
        $this->assertSame(2, $result->created);
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veilleA->id,
            'article_id' => $article->id,
            'user_id' => $userA->id,
            'matched_keyword' => 'laravel',
        ]);
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veilleB->id,
            'article_id' => $article->id,
            'user_id' => $userB->id,
            'matched_keyword' => 'php',
        ]);
    }

    public function test_no_match_when_nobody_is_subscribed(): void
    {
        $source = Source::factory()->create();
        $article = Article::factory()->for($source)->create(['title' => 'Laravel 12']);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(0, $result->examined);
        $this->assertSame(0, $result->created);
        $this->assertSame(0, ArticleVeille::count());
    }

    public function test_subscriber_without_matching_veille_creates_nothing(): void
    {
        $source = Source::factory()->create();
        $otherSource = Source::factory()->create();
        $user = User::factory()->create();
        UserSource::factory()->for($user)->for($source)->create();
        // Sa seule veille contient une autre source.
        $this->veilleOf($user, $otherSource, ['laravel']);
        $article = Article::factory()->for($source)->create(['title' => 'Laravel 12']);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(0, $result->examined);
        $this->assertSame(0, $result->created);
    }
}
