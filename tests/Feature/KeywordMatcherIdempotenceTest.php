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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KeywordMatcherIdempotenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Veille, 2: Article}
     */
    private function matchedFixture(): array
    {
        $source = Source::factory()->create();
        $user = User::factory()->create();
        UserSource::factory()->for($user)->for($source)->create();

        $veille = Veille::factory()->for($user)->create();
        $veille->sources()->attach($source->id);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);

        $article = Article::factory()->for($source)->create([
            'title' => 'Laravel 12 est sorti',
        ]);

        return [$user, $veille, $article];
    }

    private function matcher(): KeywordMatcher
    {
        return app(KeywordMatcher::class);
    }

    public function test_second_run_creates_no_duplicate(): void
    {
        [, $veille, $article] = $this->matchedFixture();

        $first = $this->matcher()->matchArticle($article);
        $second = $this->matcher()->matchArticle($article);

        $this->assertSame(1, $first->created);
        $this->assertSame(1, $second->matched);
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->existing);
        $this->assertSame(1, ArticleVeille::where('veille_id', $veille->id)
            ->where('article_id', $article->id)->count());
    }

    public function test_rerun_preserves_created_at_and_matched_keyword(): void
    {
        [, $veille, $article] = $this->matchedFixture();
        $this->matcher()->matchArticle($article);

        // Un mot-clé plus ancien et également correspondant est ajouté
        // après coup : la ligne existante ne doit pas être réécrite.
        VeilleKeyword::factory()->for($veille)->create([
            'term' => 'sorti',
            'created_at' => '2020-01-01 00:00:00',
        ]);

        $before = DB::table('article_veille')
            ->where('veille_id', $veille->id)->where('article_id', $article->id)
            ->first(['created_at', 'matched_keyword']);

        $this->matcher()->matchArticle($article);

        $after = DB::table('article_veille')
            ->where('veille_id', $veille->id)->where('article_id', $article->id)
            ->first(['created_at', 'matched_keyword']);

        $this->assertSame($before->created_at, $after->created_at);
        $this->assertSame('laravel', $after->matched_keyword);
    }

    public function test_concurrent_duplicate_insert_is_absorbed(): void
    {
        [, $veille, $article] = $this->matchedFixture();

        // Ligne concurrente pré-insérée : la PK (veille_id, article_id)
        // la tolère silencieusement, sans écraser l'historique.
        ArticleVeille::create([
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'user_id' => $veille->user_id,
            'matched_keyword' => 'concurrent',
            'created_at' => '2026-01-01 00:00:00.000001',
        ]);

        $result = $this->matcher()->matchArticle($article);

        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->existing);
        $this->assertSame(1, ArticleVeille::where('veille_id', $veille->id)
            ->where('article_id', $article->id)->count());
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => 'concurrent',
        ]);
    }

    public function test_match_row_carries_the_veille_owner_user_id(): void
    {
        [$user, $veille, $article] = $this->matchedFixture();

        $this->matcher()->matchArticle($article);

        // Cohérence de la FK composite (veille_id, user_id) : le
        // user_id de la ligne est celui du propriétaire de la veille.
        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_created_at_keeps_microsecond_precision(): void
    {
        [, $veille, $article] = $this->matchedFixture();

        $this->matcher()->matchArticle($article);

        $raw = DB::table('article_veille')
            ->where('veille_id', $veille->id)->where('article_id', $article->id)
            ->value('created_at');

        // timestamp(6) : 6 décimales exigées (bornes des digests).
        $this->assertMatchesRegularExpression('/\.\d{6}$/', (string) $raw);
    }
}
