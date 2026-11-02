<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VeilleRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_veille_can_be_created_and_read(): void
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->for($user)->create([
            'name' => 'Veille PHP',
            'description' => 'Actualités PHP',
            'alerts_enabled' => true,
        ]);

        $found = Veille::findOrFail($veille->id);

        $this->assertSame('Veille PHP', $found->name);
        $this->assertSame('Actualités PHP', $found->description);
        $this->assertTrue($found->is_active);
        $this->assertTrue($found->alerts_enabled);
        $this->assertSame($user->id, $found->user_id);
    }

    public function test_user_has_many_veilles_and_veille_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $veilles = Veille::factory()->count(2)->for($user)->create();
        Veille::factory()->create(); // veille d'un autre utilisateur

        $this->assertCount(2, $user->veilles);
        $this->assertTrue(
            $user->veilles->every(fn (Veille $veille) => $veille->user_id === $user->id),
        );
        $this->assertTrue($veilles->first()->user->is($user));
    }

    public function test_veille_keywords_relations(): void
    {
        $veille = Veille::factory()->create();
        $keyword = VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);

        // user_id dénormalisé dérivé de la veille (FK composite).
        $this->assertSame($veille->user_id, $keyword->user_id);

        $this->assertTrue($veille->keywords->contains($keyword));
        $this->assertSame('laravel', $veille->keywords->first()->term);
        $this->assertTrue($keyword->veille->is($veille));
        $this->assertSame($veille->user_id, $keyword->user->id);
    }

    public function test_veille_sources_pivot_carries_veille_user_id(): void
    {
        $veille = Veille::factory()->create();
        $source = Source::factory()->catalogue()->create();

        $veille->sources()->attach($source->id);

        $this->assertDatabaseHas('source_veille', [
            'veille_id' => $veille->id,
            'source_id' => $source->id,
            'user_id' => $veille->user_id,
        ]);

        $attached = $veille->sources()->firstOrFail();
        $this->assertSame($veille->user_id, $attached->pivot->getAttribute('user_id'));
    }

    public function test_source_veilles_inverse_relation(): void
    {
        $veille = Veille::factory()->create();
        $source = Source::factory()->create();
        $veille->sources()->attach($source->id);

        $this->assertTrue($source->veilles->contains($veille));
        $this->assertTrue(
            $source->veilles()->wherePivot('user_id', $veille->user_id)->exists(),
        );
    }

    public function test_veille_articles_pivot_exposes_match_data(): void
    {
        $veille = Veille::factory()->create();
        $article = Article::factory()->create();

        $veille->articles()->attach($article->id, [
            'matched_keyword' => 'laravel',
            'created_at' => '2026-01-15 10:30:00.123456',
        ]);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'user_id' => $veille->user_id,
            'matched_keyword' => 'laravel',
        ]);

        $match = $veille->articles()->firstOrFail()->pivot;
        $this->assertSame($veille->user_id, $match->getAttribute('user_id'));
        $this->assertSame('laravel', $match->getAttribute('matched_keyword'));
    }

    public function test_article_veilles_inverse_relation(): void
    {
        $veille = Veille::factory()->create();
        $article = Article::factory()->create();
        $veille->articles()->attach($article->id, [
            'matched_keyword' => 'php',
            'created_at' => now('UTC'),
        ]);

        $this->assertTrue($article->veilles->contains($veille));
        $this->assertTrue(
            $article->veilles()->wherePivot('matched_keyword', 'php')->exists(),
        );
    }

    public function test_article_veille_model_relations(): void
    {
        $veille = Veille::factory()->create();
        $article = Article::factory()->create();
        $match = ArticleVeille::factory()
            ->for($veille)->for($article)
            ->create(['matched_keyword' => 'php']);

        $this->assertSame($veille->user_id, $match->user_id);
        $this->assertTrue($match->veille->is($veille));
        $this->assertTrue($match->article->is($article));
        $this->assertSame($veille->user_id, $match->user->id);

        // Même ligne lue via la relation hasMany et via le belongsToMany.
        $this->assertTrue($veille->articleVeilles->contains(
            fn (ArticleVeille $m) => $m->article_id === $article->id,
        ));
        $this->assertTrue($veille->articles->contains($article));
    }

    public function test_sources_and_articles_relations_support_query_level_usage(): void
    {
        // Injection du pivot conditionnée à user_id : with/whereHas
        // instancient un modèle frais (user_id null) et ne doivent
        // pas lever d'exception.
        $veille = Veille::factory()->create();
        $veille->sources()->attach(Source::factory()->create()->id);

        $loaded = Veille::with('sources')->findOrFail($veille->id);
        $this->assertCount(1, $loaded->sources);
        $this->assertTrue(
            Veille::whereHas('sources', fn ($q) => $q->where('sources.id', $veille->sources->first()->id))
                ->whereKey($veille->id)->exists(),
        );
    }

    public function test_deleting_veille_cascades_children_but_keeps_shared_rows(): void
    {
        $veille = Veille::factory()->create();
        $source = Source::factory()->create();
        $article = Article::factory()->create();
        VeilleKeyword::factory()->for($veille)->create();
        $veille->sources()->attach($source->id);
        $veille->articles()->attach($article->id, ['created_at' => now('UTC')]);

        $veille->delete();

        $this->assertDatabaseMissing('veilles', ['id' => $veille->id]);
        $this->assertDatabaseMissing('veille_keywords', ['veille_id' => $veille->id]);
        $this->assertDatabaseMissing('source_veille', ['veille_id' => $veille->id]);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);
        // Sources et articles sont mutualisés : ils survivent.
        $this->assertDatabaseHas('sources', ['id' => $source->id]);
        $this->assertDatabaseHas('articles', ['id' => $article->id]);
    }
}
