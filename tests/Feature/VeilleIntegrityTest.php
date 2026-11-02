<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VeilleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_veille_name_is_unique_per_user(): void
    {
        $veille = Veille::factory()->create(['name' => 'Actus tech']);

        // Même nom chez un autre utilisateur : autorisé.
        Veille::factory()->create(['name' => 'Actus tech']);
        $this->assertSame(2, Veille::where('name', 'Actus tech')->count());

        // Doublon pour le même utilisateur : rejeté (UNIQUE user_id+name).
        $this->expectException(QueryException::class);
        Veille::factory()->for($veille->user)->create(['name' => 'Actus tech']);
    }

    public function test_keyword_term_is_unique_per_veille(): void
    {
        $veille = Veille::factory()->create();
        VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);

        // Même terme dans une autre veille : autorisé.
        VeilleKeyword::factory()->create(['term' => 'laravel']);
        $this->assertSame(2, VeilleKeyword::where('term', 'laravel')->count());

        // Doublon dans la même veille : rejeté (UNIQUE veille_id+term).
        $this->expectException(QueryException::class);
        VeilleKeyword::factory()->for($veille)->create(['term' => 'laravel']);
    }

    public function test_keyword_with_incoherent_user_id_is_rejected(): void
    {
        $veille = Veille::factory()->create();
        $other = User::factory()->create();

        // FK composite : (veille_id, user_id) doit exister dans veilles.
        $this->expectException(QueryException::class);
        VeilleKeyword::factory()->for($veille)->create(['user_id' => $other->id]);
    }

    public function test_source_veille_with_incoherent_user_id_is_rejected(): void
    {
        $veille = Veille::factory()->create();
        $source = Source::factory()->create();
        $other = User::factory()->create();

        $this->expectException(QueryException::class);
        $veille->sources()->attach($source->id, ['user_id' => $other->id]);
    }

    public function test_article_veille_with_incoherent_user_id_is_rejected(): void
    {
        $veille = Veille::factory()->create();
        $article = Article::factory()->create();
        $other = User::factory()->create();

        $this->expectException(QueryException::class);
        $veille->articles()->attach($article->id, [
            'user_id' => $other->id,
            'created_at' => now('UTC'),
        ]);
    }

    public function test_article_veille_model_with_incoherent_user_id_is_rejected(): void
    {
        $veille = Veille::factory()->create();
        $other = User::factory()->create();

        $this->expectException(QueryException::class);
        ArticleVeille::factory()->for($veille)->create(['user_id' => $other->id]);
    }

    public function test_null_keyword_term_is_rejected_by_database(): void
    {
        $veille = Veille::factory()->create();

        // term null : laissé tel quel par le hook (pas de coercion en
        // ''), la contrainte NOT NULL le rejette — aucun warning PHP.
        $this->expectException(QueryException::class);
        VeilleKeyword::create([
            'veille_id' => $veille->id,
            'user_id' => $veille->user_id,
            'term' => null,
        ]);
    }

    public function test_keyword_without_existing_veille_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        VeilleKeyword::factory()->create(['veille_id' => 999999, 'user_id' => 1]);
    }

    public function test_duplicate_source_attachment_is_rejected(): void
    {
        $veille = Veille::factory()->create();
        $source = Source::factory()->create();
        $veille->sources()->attach($source->id);

        $this->expectException(QueryException::class);
        $veille->sources()->attach($source->id);
    }

    public function test_duplicate_article_match_is_rejected(): void
    {
        $veille = Veille::factory()->create();
        $article = Article::factory()->create();
        $veille->articles()->attach($article->id, ['created_at' => now('UTC')]);

        $this->expectException(QueryException::class);
        $veille->articles()->attach($article->id, ['created_at' => now('UTC')]);
    }

    public function test_article_veille_created_at_preserves_microseconds(): void
    {
        $veille = Veille::factory()->create();
        $article = Article::factory()->create();
        $veille->articles()->attach($article->id, [
            'created_at' => '2026-01-15 10:30:00.123456',
        ]);

        $raw = DB::table('article_veille')
            ->where('veille_id', $veille->id)
            ->where('article_id', $article->id)
            ->value('created_at');

        $this->assertSame('123456', Carbon::parse($raw)->format('u'));
    }

    public function test_article_veille_update_targets_composite_key(): void
    {
        $match = ArticleVeille::factory()->create(['matched_keyword' => 'php']);

        $match->update(['matched_keyword' => 'laravel']);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $match->veille_id,
            'article_id' => $match->article_id,
            'matched_keyword' => 'laravel',
        ]);
    }
}
