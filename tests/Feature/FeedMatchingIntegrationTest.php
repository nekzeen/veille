<?php

namespace Tests\Feature;

use App\Jobs\FetchFeedJob;
use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use App\Services\Feed\ArticleUpsertService;
use App\Services\Feed\Connectors\ConnectorRegistry;
use App\Services\Feed\FeedClientInterface;
use App\Services\Feed\FeedParserInterface;
use App\Services\KeywordMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeFeedClient;
use Tests\Fakes\SpyFeedParser;
use Tests\TestCase;

/**
 * Intégration M1.11.4 : pipeline réel collecte → parse → persistance
 * → matching, avec client HTTP faké et matcher RÉEL (pas de mock).
 */
class FeedMatchingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Store `database` comme en production (verrous réels).
        config(['cache.default' => 'database']);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/feeds/'.$name));
    }

    private function source(): Source
    {
        return Source::factory()->create(['last_fetched_at' => null]);
    }

    private function runJob(Source $source): void
    {
        app()->instance(FeedClientInterface::class, FakeFeedClient::ok(self::fixture('rss20_basic.xml')));
        app()->instance(FeedParserInterface::class, new SpyFeedParser);
        (new FetchFeedJob($source->id))->handle(
            app(ConnectorRegistry::class),
            app(ArticleUpsertService::class),
            app(KeywordMatcher::class),
        );
    }

    /** Rends la source à nouveau « due » pour une collecte suivante. */
    private function makeDue(Source $source): void
    {
        Source::whereKey($source->id)->update(['last_fetched_at' => now()->subMinutes(20)]);
    }

    /** @param  list<string>  $terms */
    private function subscribedVeille(Source $source, User $user, array $terms, bool $active = true): Veille
    {
        UserSource::firstOrCreate(
            ['user_id' => $user->id, 'source_id' => $source->id],
            ['created_at' => now('UTC')],
        );
        $veille = Veille::factory()->for($user)->create(['is_active' => $active]);
        $veille->sources()->attach($source->id);
        foreach ($terms as $term) {
            VeilleKeyword::factory()->for($veille)->create(['term' => $term]);
        }

        return $veille;
    }

    public function test_new_article_matching_a_veille_is_associated(): void
    {
        $source = $this->source();
        $user = User::factory()->create();
        $veille = $this->subscribedVeille($source, $user, ['complet']);

        $this->runJob($source);

        $article = Article::where('title', 'Article complet')->sole();
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'user_id' => $user->id,
            'matched_keyword' => 'complet',
        ]);
        // L'autre item du flux ne correspond à aucun mot-clé.
        $this->assertSame(1, ArticleVeille::count());
    }

    public function test_no_match_when_no_keyword_fits(): void
    {
        $source = $this->source();
        $this->subscribedVeille($source, User::factory()->create(), ['inexistant']);

        $this->runJob($source);

        $this->assertSame(2, Article::count());
        $this->assertSame(0, ArticleVeille::count());
    }

    public function test_all_matching_articles_of_a_batch_are_processed(): void
    {
        $source = $this->source();
        $user = User::factory()->create();
        $veille = $this->subscribedVeille($source, $user, ['article']);

        $this->runJob($source);

        // « Article complet » et « Deuxième article » contiennent
        // tous deux « article » dans leur titre.
        $this->assertSame(2, ArticleVeille::where('veille_id', $veille->id)->count());
    }

    public function test_one_article_can_match_several_veilles_of_same_user(): void
    {
        $source = $this->source();
        $user = User::factory()->create();
        $v1 = $this->subscribedVeille($source, $user, ['complet']);
        $v2 = $this->subscribedVeille($source, $user, ['article']);

        $this->runJob($source);

        $article = Article::where('title', 'Article complet')->sole();
        foreach ([$v1, $v2] as $veille) {
            $this->assertDatabaseHas('article_veille', [
                'veille_id' => $veille->id,
                'article_id' => $article->id,
                'user_id' => $user->id,
            ]);
        }
        // Un seul abonnement malgré deux veilles sur la même source.
        $this->assertSame(1, UserSource::where('user_id', $user->id)->where('source_id', $source->id)->count());
    }

    public function test_shared_source_matches_each_subscriber_privately(): void
    {
        $source = $this->source();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $veilleA = $this->subscribedVeille($source, $userA, ['complet']);
        $veilleB = $this->subscribedVeille($source, $userB, ['article']);

        $this->runJob($source);

        $article = Article::where('title', 'Article complet')->sole();
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veilleA->id, 'article_id' => $article->id,
            'user_id' => $userA->id, 'matched_keyword' => 'complet',
        ]);
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veilleB->id, 'article_id' => $article->id,
            'user_id' => $userB->id, 'matched_keyword' => 'article',
        ]);
    }

    public function test_inactive_veille_receives_no_match(): void
    {
        $source = $this->source();
        $veille = $this->subscribedVeille($source, User::factory()->create(), ['complet'], active: false);

        $this->runJob($source);

        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
    }

    public function test_known_article_matches_a_veille_created_after_collection(): void
    {
        $source = $this->source();
        // Pas de veille : les articles sont collectés sans correspondance.
        $this->runJob($source);
        $this->assertSame(0, ArticleVeille::count());

        // Veille créée ensuite → la collecte suivante re-traite les
        // articles rencontrés à nouveau (mêmes items du flux).
        $user = User::factory()->create();
        $veille = $this->subscribedVeille($source, $user, ['complet']);
        $this->makeDue($source);
        $this->runJob($source);

        $article = Article::where('title', 'Article complet')->sole();
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_rerun_does_not_duplicate_matches(): void
    {
        $source = $this->source();
        $veille = $this->subscribedVeille($source, User::factory()->create(), ['complet']);

        $this->runJob($source);
        $this->makeDue($source);
        $this->runJob($source);

        $this->assertSame(1, ArticleVeille::where('veille_id', $veille->id)->count());
    }

    public function test_matcher_error_keeps_articles_and_job_success(): void
    {
        $spy = Log::spy();
        $source = $this->source();
        $this->subscribedVeille($source, User::factory()->create(), ['complet']);

        // Échec transitoire du matching : l'écouteur `creating` lève
        // avant tout insert article_veille.
        Event::listen('eloquent.creating: '.ArticleVeille::class, function (): void {
            throw new \RuntimeException('panne de matching');
        });

        $this->runJob($source);

        // Ni échec HTTP ni perte : succès enregistré, articles persistés.
        $this->assertSame(2, Article::count());
        $this->assertSame(0, ArticleVeille::count());
        $fresh = $source->fresh();
        $this->assertSame(0, $fresh->fail_count);
        $this->assertNotNull($fresh->last_success_at);
        $spy->shouldHaveReceived('error')->with('feeds.fetch.match_failed', \Mockery::type('array'));
    }

    public function test_next_collect_repairs_missing_matches(): void
    {
        $source = $this->source();
        $user = User::factory()->create();
        $veille = $this->subscribedVeille($source, $user, ['complet']);

        $event = 'eloquent.creating: '.ArticleVeille::class;
        Event::listen($event, function (): void {
            throw new \RuntimeException('panne transitoire');
        });
        $this->runJob($source);
        $this->assertSame(0, ArticleVeille::count());
        Event::forget($event);

        // La collecte suivante répare : les articles du lot sont
        // rencontrés à nouveau et le matcher est idempotent.
        $this->makeDue($source);
        $this->runJob($source);

        $article = Article::where('title', 'Article complet')->sole();
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id, 'article_id' => $article->id, 'user_id' => $user->id,
        ]);
    }
}
