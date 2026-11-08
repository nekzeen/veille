<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\User;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleReadStateTest extends TestCase
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

    private function readUrl(Veille $veille, Article $article): string
    {
        return route('veilles.articles.read', [$veille, $article]);
    }

    // ---------- Isolation ----------

    public function test_etat_lu_est_strictement_personnel(): void
    {
        [$userA, $veilleA] = $this->userWithVeille();
        $article = $this->match($veilleA, ['title' => 'Partagé']);
        [$userB, $veilleB] = $this->userWithVeille();
        $this->match($veilleB, [], 'kw');   // veille B a aussi l'article
        ArticleVeille::factory()->create([
            'veille_id' => $veilleB->id, 'article_id' => $article->id,
        ]);

        $this->actingAs($userA)->put($this->readUrl($veilleA, $article))->assertRedirect();

        // A voit « Lu », B voit toujours « Non lu ».
        $this->assertDatabaseHas('article_user', [
            'user_id' => $userA->id, 'article_id' => $article->id, 'is_read' => true,
        ]);
        $this->assertDatabaseMissing('article_user', ['user_id' => $userB->id]);
        $this->actingAs($userB)->get(route('veilles.articles.index', $veilleB))
            ->assertSee('Non lu', false)->assertDontSee('>Lu<', false);
    }

    public function test_b_marque_lu_sans_impacter_a(): void
    {
        [$userA, $veilleA] = $this->userWithVeille();
        $article = $this->match($veilleA);
        [$userB, $veilleB] = $this->userWithVeille();
        ArticleVeille::factory()->create([
            'veille_id' => $veilleB->id, 'article_id' => $article->id,
        ]);

        $this->actingAs($userA)->put($this->readUrl($veilleA, $article));
        $this->actingAs($userB)->put($this->readUrl($veilleB, $article));
        $this->actingAs($userB)->delete($this->readUrl($veilleB, $article));

        // B est repassé non lu (sa ligne est supprimée) ; A reste lu.
        $this->assertDatabaseMissing('article_user', ['user_id' => $userB->id]);
        $this->assertDatabaseHas('article_user', [
            'user_id' => $userA->id, 'article_id' => $article->id, 'is_read' => true,
        ]);
    }

    public function test_acces_non_autorise_refuse(): void
    {
        [$proprietaire, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $intrus = User::factory()->create();

        // Veille d'un autre → 403 (policy) ; article d'une autre veille → 404.
        $this->actingAs($intrus)->put($this->readUrl($veille, $article))->assertForbidden();
        $this->actingAs($intrus)->delete($this->readUrl($veille, $article))->assertForbidden();

        [$user, $veille2] = $this->userWithVeille();
        $autre = Veille::factory()->create(['user_id' => $user->id]);
        $article2 = $this->match($autre);   // associé à l'autre veille seulement
        $this->actingAs($user)->put($this->readUrl($veille2, $article2))->assertNotFound();
    }

    // ---------- Fonctionnement ----------

    public function test_sans_ligne_considere_non_lu(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $this->match($veille);

        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertSee('Non lu', false);
    }

    public function test_marquer_lu_puis_non_lu(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->actingAs($user)->put($this->readUrl($veille, $article))->assertRedirect();
        $this->assertDatabaseHas('article_user', [
            'user_id' => $user->id, 'article_id' => $article->id, 'is_read' => true,
        ]);

        // La ligne sans autre donnée personnelle est supprimée.
        $this->actingAs($user)->delete($this->readUrl($veille, $article))->assertRedirect();
        $this->assertDatabaseMissing('article_user', [
            'user_id' => $user->id, 'article_id' => $article->id,
        ]);
    }

    public function test_operations_idempotentes(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->actingAs($user)->put($this->readUrl($veille, $article));
        $this->actingAs($user)->put($this->readUrl($veille, $article));
        $this->assertSame(1, ArticleUser::where('user_id', $user->id)->count());

        $this->actingAs($user)->delete($this->readUrl($veille, $article));
        $this->actingAs($user)->delete($this->readUrl($veille, $article));   // déjà absent → rien
        $this->assertSame(0, ArticleUser::where('user_id', $user->id)->count());
    }

    public function test_etat_visible_en_liste_et_en_lecture(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $this->actingAs($user)->put($this->readUrl($veille, $article));

        $this->actingAs($user)->get(route('veilles.articles.index', [$veille, 'state' => 'all']))
            ->assertSee('>Lu<', false);
        $this->actingAs($user)->get(route('veilles.articles.show', [$veille, $article]))
            ->assertSee('>Lu<', false)->assertSee('Marquer non lu');
    }

    // ---------- Préservation des données ----------

    public function test_non_lu_preserve_les_autres_donnees_personnelles(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_read' => true, 'is_favorite' => true, 'note' => 'à relire',
        ]);

        $this->actingAs($user)->delete($this->readUrl($veille, $article));

        // La ligne est conservée : is_read → 0, favori et note intacts.
        $this->assertDatabaseHas('article_user', [
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_read' => false, 'is_favorite' => true, 'note' => 'à relire',
        ]);
    }

    public function test_article_veille_et_autres_utilisateurs_intacts(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $autre = User::factory()->create();
        ArticleUser::factory()->create([
            'user_id' => $autre->id, 'article_id' => $article->id, 'is_read' => true,
        ]);

        $this->actingAs($user)->put($this->readUrl($veille, $article));
        $this->actingAs($user)->delete($this->readUrl($veille, $article));

        $this->assertDatabaseHas('article_veille', [
            'veille_id' => $veille->id, 'article_id' => $article->id,
        ]);
        $this->assertDatabaseHas('article_user', [
            'user_id' => $autre->id, 'article_id' => $article->id, 'is_read' => true,
        ]);
    }

    // ---------- Sécurité et robustesse ----------

    public function test_invite_redirige_vers_login(): void
    {
        [, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->put($this->readUrl($veille, $article))->assertRedirect('/login');
        $this->delete($this->readUrl($veille, $article))->assertRedirect('/login');
    }

    public function test_article_inexistant_ou_malforme(): void
    {
        [$user, $veille] = $this->userWithVeille();

        $this->actingAs($user)->put("/veilles/{$veille->id}/articles/999999/read")->assertNotFound();
        $this->actingAs($user)->put("/veilles/{$veille->id}/articles/abc/read")->assertNotFound();
    }

    public function test_mutation_inaccessible_en_get(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        // Seules les mutations PUT/DELETE existent (CSRF protégé) — pas de GET.
        $response = $this->actingAs($user)->get($this->readUrl($veille, $article));
        $this->assertNotSame(200, $response->status());
    }

    public function test_user_id_du_client_ignore(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $autre = User::factory()->create();

        // Un user_id injecté dans le corps n'influence rien.
        $this->actingAs($user)->put($this->readUrl($veille, $article), [
            'user_id' => $autre->id, 'is_read' => '0',
        ]);

        $this->assertDatabaseHas('article_user', [
            'user_id' => $user->id, 'article_id' => $article->id, 'is_read' => true,
        ]);
        $this->assertDatabaseMissing('article_user', ['user_id' => $autre->id]);
    }
}
