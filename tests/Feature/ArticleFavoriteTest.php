<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\User;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArticleFavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function userWithVeille(): array
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->create(['user_id' => $user->id]);

        return [$user, $veille];
    }

    private function match(Veille $veille, array $article = [], ?string $keyword = 'kw'): Article
    {
        $article = Article::factory()->create($article);
        ArticleVeille::factory()->create([
            'veille_id' => $veille->id,
            'article_id' => $article->id,
            'matched_keyword' => $keyword,
        ]);

        return $article;
    }

    // ---------- Fonctionnement ----------

    public function test_ajout_et_retrait_du_favori(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->actingAs($user)->put(route('veilles.articles.favorite', [$veille, $article]))
            ->assertRedirect();
        $this->assertDatabaseHas('article_user', [
            'user_id' => $user->id, 'article_id' => $article->id, 'is_favorite' => true,
        ]);

        // Ligne sans autre donnée → supprimée au retrait.
        $this->actingAs($user)->delete(route('veilles.articles.unfavorite', [$veille, $article]))
            ->assertRedirect();
        $this->assertDatabaseMissing('article_user', [
            'user_id' => $user->id, 'article_id' => $article->id,
        ]);
    }

    public function test_operations_idempotentes(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->actingAs($user)->put(route('veilles.articles.favorite', [$veille, $article]));
        $this->actingAs($user)->put(route('veilles.articles.favorite', [$veille, $article]));
        $this->assertSame(1, ArticleUser::where('user_id', $user->id)->count());

        $this->actingAs($user)->delete(route('veilles.articles.unfavorite', [$veille, $article]));
        $this->actingAs($user)->delete(route('veilles.articles.unfavorite', [$veille, $article]));
        $this->assertSame(0, ArticleUser::where('user_id', $user->id)->count());
    }

    public function test_isolation_entre_deux_utilisateurs(): void
    {
        [$userA, $veilleA] = $this->userWithVeille();
        $article = $this->match($veilleA);
        [$userB, $veilleB] = $this->userWithVeille();
        ArticleVeille::factory()->create([
            'veille_id' => $veilleB->id, 'article_id' => $article->id,
        ]);

        $this->actingAs($userA)->put(route('veilles.articles.favorite', [$veilleA, $article]));

        // Favori de A seul ; B garde sa liste vide.
        $this->assertDatabaseHas('article_user', [
            'user_id' => $userA->id, 'article_id' => $article->id, 'is_favorite' => true,
        ]);
        $this->assertDatabaseMissing('article_user', ['user_id' => $userB->id]);
        $this->actingAs($userB)->get(route('favorites.index'))
            ->assertOk()->assertSee('pas encore de favori');
    }

    public function test_retrait_preserve_les_autres_donnees_personnelles(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        // FK composite (user_id, category_id) → categories : vraie ligne requise.
        $categoryId = DB::table('categories')->insertGetId([
            'user_id' => $user->id, 'name' => 'Cat', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_read' => true, 'is_favorite' => true, 'note' => 'mémo', 'category_id' => $categoryId,
        ]);

        $this->actingAs($user)->delete(route('veilles.articles.unfavorite', [$veille, $article]));

        $this->assertDatabaseHas('article_user', [
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_favorite' => false, 'is_read' => true, 'note' => 'mémo', 'category_id' => $categoryId,
        ]);
    }

    public function test_favori_survit_a_la_suppression_de_la_correspondance(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, ['title' => 'Mon favori']);

        $this->actingAs($user)->put(route('veilles.articles.favorite', [$veille, $article]));

        // La correspondance disparaît : le favori conserve l'accès (§4.D-bis).
        ArticleVeille::where('veille_id', $veille->id)->where('article_id', $article->id)->delete();

        $this->actingAs($user)->get(route('favorites.index'))
            ->assertOk()->assertSee('Mon favori');
        $this->actingAs($user)->get(route('articles.show', $article))
            ->assertOk()->assertSee('Mon favori');
        $this->actingAs($user)->delete(route('articles.unfavorite', $article))
            ->assertRedirect();
        $this->assertDatabaseMissing('article_user', [
            'user_id' => $user->id, 'article_id' => $article->id,
        ]);
    }

    public function test_acces_refuse_pour_article_dun_autre_utilisateur(): void
    {
        [$userA, $veilleA] = $this->userWithVeille();
        $article = $this->match($veilleA);
        $intrus = User::factory()->create();

        // Ni correspondance ni ligne personnelle → 404, pas de fuite.
        $this->actingAs($intrus)->get(route('articles.show', $article))->assertNotFound();
        $this->actingAs($intrus)->put(route('articles.favorite', $article))->assertNotFound();
        $this->actingAs($intrus)->put(route('veilles.articles.favorite', [$veilleA, $article]))->assertForbidden();
        $this->assertDatabaseMissing('article_user', ['user_id' => $intrus->id]);
    }

    public function test_liste_favoris_triee_et_paginee(): void
    {
        [$user, $veille] = $this->userWithVeille();
        for ($i = 1; $i <= 22; $i++) {
            $a = $this->match($veille, [
                'title' => "Fav {$i}",
                'published_at' => now('UTC')->subMinutes($i),
            ]);
            ArticleUser::factory()->create([
                'user_id' => $user->id, 'article_id' => $a->id, 'is_favorite' => true,
            ]);
        }

        $this->actingAs($user)->get(route('favorites.index'))
            ->assertOk()->assertSeeInOrder(['Fav 1', 'Fav 2'])->assertDontSee('Fav 21');
        $this->actingAs($user)->get(route('favorites.index', ['page' => 2]))
            ->assertOk()->assertSee('Fav 21');
    }

    public function test_published_at_null_retombe_sur_collected_at(): void
    {
        [$user, $veille] = $this->userWithVeille();
        foreach ([
            ['title' => 'Sans date', 'published_at' => null, 'collected_at' => now('UTC')->subDay()],
            ['title' => 'Avec date', 'published_at' => now('UTC')->subDays(2), 'collected_at' => now('UTC')],
        ] as $attrs) {
            $a = $this->match($veille, $attrs);
            ArticleUser::factory()->create([
                'user_id' => $user->id, 'article_id' => $a->id, 'is_favorite' => true,
            ]);
        }

        $this->actingAs($user)->get(route('favorites.index'))
            ->assertOk()->assertSeeInOrder(['Sans date', 'Avec date']);
    }

    public function test_article_veille_et_autres_utilisateurs_intacts(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $autre = User::factory()->create();
        ArticleUser::factory()->create([
            'user_id' => $autre->id, 'article_id' => $article->id, 'is_favorite' => true,
        ]);

        $this->actingAs($user)->put(route('veilles.articles.favorite', [$veille, $article]));
        $this->actingAs($user)->delete(route('veilles.articles.unfavorite', [$veille, $article]));

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id, 'article_id' => $article->id,
        ]);
        $this->assertDatabaseHas('article_user', [
            'user_id' => $autre->id, 'article_id' => $article->id, 'is_favorite' => true,
        ]);
    }

    // ---------- Sécurité et robustesse ----------

    public function test_invite_redirige_vers_login(): void
    {
        [, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->get(route('favorites.index'))->assertRedirect('/login');
        $this->get(route('articles.show', $article))->assertRedirect('/login');
        $this->put(route('articles.favorite', $article))->assertRedirect('/login');
        $this->delete(route('articles.unfavorite', $article))->assertRedirect('/login');
    }

    public function test_article_inexistant(): void
    {
        [$user, $veille] = $this->userWithVeille();

        $this->actingAs($user)->get('/articles/999999')->assertNotFound();
        $this->actingAs($user)->put('/articles/999999/favorite')->assertNotFound();
    }
}
