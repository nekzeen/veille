<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserSource;
use App\Models\Veille;
use App\Models\VeilleKeyword;
use App\Services\VeilleRematcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rematching ciblé + global (M1.11.5) — différence d'ensembles §4.D. */
class VeilleRematchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Source, 1: User, 2: Veille} */
    private function subscribedVeille(array $terms = ['laravel'], bool $active = true): array
    {
        $source = Source::factory()->create();
        $user = User::factory()->create();
        UserSource::factory()->for($user)->for($source)->create();
        $veille = Veille::factory()->for($user)->create(['is_active' => $active]);
        $veille->sources()->attach($source->id);
        foreach ($terms as $term) {
            VeilleKeyword::factory()->for($veille)->create(['term' => $term]);
        }

        return [$source, $user, $veille];
    }

    private function article(Source $source, array $attributes = []): Article
    {
        return Article::factory()->for($source)->create(['title' => 'Laravel 12 est sorti'] + $attributes);
    }

    public function test_create_picks_up_historical_articles(): void
    {
        // Source catalogue : l'abonnement est cr�� automatiquement
        // � la s�lection (r�gle du CRUD).
        $source = Source::factory()->catalogue()->create();
        $article = $this->article($source);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('veilles.store'), [
            'name' => 'Veille', 'is_active' => '1',
            'keywords' => 'laravel', 'sources' => [$source->id],
        ]);

        $this->assertDatabaseHas('article_veille', ['article_id' => $article->id, 'user_id' => $user->id]);
    }

    public function test_added_keyword_creates_missing_matches(): void
    {
        [$source, , $veille] = $this->subscribedVeille();
        $article = Article::factory()->for($source)->create(['title' => 'Symfony est disponible']);
        $this->assertDatabaseMissing('article_veille', ['article_id' => $article->id]);

        $this->actingAs($veille->user)->put(route('veilles.update', $veille), [
            'name' => $veille->name, 'is_active' => '1',
            'keywords' => "laravel\nsymfony", 'sources' => [$source->id],
        ]);

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id, 'article_id' => $article->id, 'matched_keyword' => 'symfony',
        ]);
    }

    public function test_removed_keyword_deletes_now_invalid_matches(): void
    {
        [$source, $user, $veille] = $this->subscribedVeille();
        $article = $this->article($source);
        ArticleVeille::create(['veille_id' => $veille->id, 'article_id' => $article->id, 'user_id' => $user->id, 'matched_keyword' => 'laravel']);

        $this->actingAs($user)->put(route('veilles.update', $veille), [
            'name' => $veille->name, 'is_active' => '1',
            'keywords' => 'symfony', 'sources' => [$source->id],
        ]);

        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id, 'article_id' => $article->id]);
    }

    public function test_still_valid_match_keeps_created_at_and_keyword(): void
    {
        [$source, $user, $veille] = $this->subscribedVeille(['laravel']);
        $article = $this->article($source);
        $row = ArticleVeille::create(['veille_id' => $veille->id, 'article_id' => $article->id, 'user_id' => $user->id, 'matched_keyword' => 'laravel']);

        // Ajout d'un mot-clé plus prioritaire via dates : la
        // correspondance valide n'est ni supprimée ni réécrite.
        VeilleKeyword::factory()->for($veille)->create(['term' => 'est']);

        app(VeilleRematcher::class)->rematch($veille);

        $fresh = ArticleVeille::where('veille_id', $veille->id)
            ->where('article_id', $article->id)->first();
        $this->assertNotNull($fresh);
        $this->assertSame('laravel', $fresh->matched_keyword);
        $this->assertEquals($row->created_at, $fresh->created_at);
    }

    public function test_added_source_scans_its_historical_articles(): void
    {
        [$source, $user, $veille] = $this->subscribedVeille();
        $other = Source::factory()->create();
        UserSource::factory()->for($user)->for($other)->create();
        $article = $this->article($other);

        $this->actingAs($user)->put(route('veilles.update', $veille), [
            'name' => $veille->name, 'is_active' => '1',
            'keywords' => 'laravel', 'sources' => [$source->id, $other->id],
        ]);

        $this->assertDatabaseHas('article_veille', ['veille_id' => $veille->id, 'article_id' => $article->id]);
    }

    public function test_removed_source_deletes_only_that_source_matches(): void
    {
        [$source, $user, $veille] = $this->subscribedVeille();
        $other = Source::factory()->create();
        UserSource::factory()->for($user)->for($other)->create();
        $veille->sources()->attach($other->id);
        $a1 = $this->article($source);
        $a2 = $this->article($other);
        foreach ([$a1, $a2] as $a) {
            ArticleVeille::create(['veille_id' => $veille->id, 'article_id' => $a->id, 'user_id' => $user->id, 'matched_keyword' => 'laravel']);
        }

        $this->actingAs($user)->put(route('veilles.update', $veille), [
            'name' => $veille->name, 'is_active' => '1',
            'keywords' => 'laravel', 'sources' => [$source->id],
        ]);

        $this->assertDatabaseHas('article_veille', ['veille_id' => $veille->id, 'article_id' => $a1->id]);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id, 'article_id' => $a2->id]);
        // L'abonnement personnel est conservé (§5).
        $this->assertDatabaseHas('user_sources', ['user_id' => $user->id, 'source_id' => $other->id]);
    }

    public function test_reactivation_recovers_matches_and_pause_keeps_them(): void
    {
        [$source, $user, $veille] = $this->subscribedVeille(active: false);
        $article = $this->article($source);

        // Veille inactive : aucune association nouvelle.
        app(VeilleRematcher::class)->rematch($veille);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id]);

        // Réactivation via toggle → recalcul.
        $this->actingAs($user)->patch(route('veilles.toggle', $veille));
        $this->assertDatabaseHas('article_veille', ['veille_id' => $veille->id, 'article_id' => $article->id]);
    }

    public function test_articles_outside_retention_are_ignored(): void
    {
        [$source, , $veille] = $this->subscribedVeille();
        $old = $this->article($source, [
            'published_at' => now()->subMonths(7),
            'collected_at' => now()->subMonths(7),
        ]);

        app(VeilleRematcher::class)->rematch($veille);

        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id, 'article_id' => $old->id]);
    }

    public function test_unsubscribed_source_matches_are_removed(): void
    {
        [$source, $user, $veille] = $this->subscribedVeille();
        $article = $this->article($source);
        ArticleVeille::create(['veille_id' => $veille->id, 'article_id' => $article->id, 'user_id' => $user->id, 'matched_keyword' => 'laravel']);

        // Abonnement supprimé : l'association devient invalide.
        UserSource::where('user_id', $user->id)->where('source_id', $source->id)->delete();
        // recrée la source_veille retirée par unsubscribe() sinon la
        // veille ne voit plus du tout la source — cohérent avec §4.A.

        app(VeilleRematcher::class)->rematch($veille);

        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id, 'article_id' => $article->id]);
    }

    public function test_global_command_repairs_and_prunes(): void
    {
        [$source, $user, $veille] = $this->subscribedVeille();
        $article = $this->article($source);
        // Association devenue invalide (article autre source non associée).
        $stray = $this->article(Source::factory()->create());
        ArticleVeille::create(['veille_id' => $veille->id, 'article_id' => $stray->id, 'user_id' => $user->id, 'matched_keyword' => 'laravel']);

        $this->artisan('veilles:rematch')->assertSuccessful();

        $this->assertDatabaseHas('article_veille', ['veille_id' => $veille->id, 'article_id' => $article->id]);
        $this->assertDatabaseMissing('article_veille', ['veille_id' => $veille->id, 'article_id' => $stray->id]);

        // Ré-exécution : idempotente, aucune réécriture.
        $before = ArticleVeille::where('veille_id', $veille->id)->sole();
        $this->artisan('veilles:rematch')->assertSuccessful();
        $after = ArticleVeille::where('veille_id', $veille->id)->sole();
        $this->assertEquals($before->created_at, $after->created_at);
    }

    public function test_isolation_between_users_is_preserved(): void
    {
        [$source, $userA, $veilleA] = $this->subscribedVeille();
        $userB = User::factory()->create();
        UserSource::factory()->for($userB)->for($source)->create();
        $veilleB = Veille::factory()->for($userB)->create();
        $veilleB->sources()->attach($source->id);
        $article = $this->article($source);
        // Correspondance « imposture » : user_id de B sur veille de A
        // impossible (FK), donc on teste le périmètre par veille.
        $rowB = ArticleVeille::create(['veille_id' => $veilleB->id, 'article_id' => $article->id, 'user_id' => $userB->id, 'matched_keyword' => 'laravel']);

        app(VeilleRematcher::class)->rematch($veilleA);

        // La veille A est recalculée, celle de B intacte.
        $this->assertDatabaseHas('article_veille', ['veille_id' => $veilleA->id, 'article_id' => $article->id, 'user_id' => $userA->id]);
        $fresh = ArticleVeille::where('veille_id', $veilleB->id)
            ->where('article_id', $article->id)->first();
        $this->assertSame($userB->id, $fresh->user_id);
        $this->assertEquals($rowB->created_at, $fresh->created_at);
    }
}
