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

class ArticleNoteTest extends TestCase
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
            'veille_id' => $veille->id, 'article_id' => $article->id,
            'matched_keyword' => $keyword,
        ]);

        return $article;
    }

    private function veilleNoteUrl(Veille $veille, Article $article): string
    {
        return route('veilles.articles.note', [$veille, $article]);
    }

    private function row(int $userId, int $articleId): ?ArticleUser
    {
        return ArticleUser::where('user_id', $userId)->where('article_id', $articleId)->first();
    }

    public function test_creation_modification_suppression(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $show = route('veilles.articles.show', [$veille, $article]);

        $this->actingAs($user)->put($this->veilleNoteUrl($veille, $article), ['note' => '  Ma note  '])
            ->assertRedirect();
        $this->assertSame('Ma note', $this->row($user->id, $article->id)?->note);
        $this->actingAs($user)->get($show)->assertSee('Ma note', false);

        $this->actingAs($user)->put($this->veilleNoteUrl($veille, $article), ['note' => 'V2'])
            ->assertRedirect();
        $this->assertSame('V2', $this->row($user->id, $article->id)?->note);
        $this->assertSame(1, ArticleUser::where('user_id', $user->id)->count());

        $this->actingAs($user)->delete(route('veilles.articles.note.delete', [$veille, $article]))
            ->assertRedirect();
        $this->assertNull($this->row($user->id, $article->id));
    }

    public function test_note_composee_despaces_est_absence(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id, 'note' => 'texte',
        ]);

        $this->actingAs($user)->put($this->veilleNoteUrl($veille, $article), ['note' => "   \n\t  "])
            ->assertRedirect();
        $this->assertNull($this->row($user->id, $article->id));
    }

    public function test_note_dun_autre_utilisateur_inaccessible(): void
    {
        [$userA, $veilleA] = $this->userWithVeille();
        $article = $this->match($veilleA);
        $intrus = User::factory()->create();
        ArticleUser::factory()->create([
            'user_id' => $userA->id, 'article_id' => $article->id, 'note' => 'secrète',
        ]);

        // Ni lecture ni mutation : l'accès vient de sa veille ou de sa ligne.
        $this->actingAs($intrus)->put(route('articles.note', $article), ['note' => 'x'])->assertNotFound();
        $this->actingAs($intrus)->delete(route('articles.note.delete', $article))->assertNotFound();
        $this->assertSame('secrète', $this->row($userA->id, $article->id)?->note);
        $this->assertDatabaseMissing('article_user', ['user_id' => $intrus->id]);
    }

    public function test_note_preserve_read_favorite_categorie(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $categoryId = DB::table('categories')->insertGetId([
            'user_id' => $user->id, 'name' => 'C', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_read' => true, 'is_favorite' => true, 'category_id' => $categoryId,
        ]);

        $this->actingAs($user)->put($this->veilleNoteUrl($veille, $article), ['note' => 'n']);
        $row = $this->row($user->id, $article->id);
        $this->assertTrue($row->is_read && $row->is_favorite && $row->category_id === $categoryId && $row->note === 'n');

        // Suppression de la note : tout le reste survit.
        $this->actingAs($user)->delete(route('veilles.articles.note.delete', [$veille, $article]));
        $row = $this->row($user->id, $article->id);
        $this->assertNotNull($row);
        $this->assertNull($row->note);
        $this->assertTrue($row->is_read && $row->is_favorite);
    }

    public function test_note_survit_a_la_suppression_de_la_correspondance(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, ['title' => 'Avec note']);

        $this->actingAs($user)->put($this->veilleNoteUrl($veille, $article), ['note' => 'conservée']);
        ArticleVeille::where('veille_id', $veille->id)->where('article_id', $article->id)->delete();

        // Ligne personnelle = droit d'accès autonome (§4.D-bis).
        $this->actingAs($user)->get(route('articles.show', $article))
            ->assertOk()->assertSee('conservée');
        $this->actingAs($user)->put(route('articles.note', $article), ['note' => 'maj'])
            ->assertRedirect(route('articles.show', $article));
        $this->assertSame('maj', $this->row($user->id, $article->id)?->note);
    }

    public function test_article_sans_aucun_acces_inaccessible(): void
    {
        [$user] = [User::factory()->create()];
        $article = Article::factory()->create();

        $this->actingAs($user)->put(route('articles.note', $article), ['note' => 'x'])->assertNotFound();
        $this->actingAs($user)->get(route('articles.show', $article))->assertNotFound();
    }

    public function test_note_protege_de_la_purge(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, [
            'published_at' => now('UTC')->subMonths(7),
            'collected_at' => now('UTC')->subMonths(7),
        ]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id, 'note' => 'garder',
        ]);

        // Hors fenêtre veille mais accessible par accès personnel (note).
        $this->actingAs($user)->get(route('articles.show', $article))->assertOk();
        $this->assertNotNull($this->row($user->id, $article->id));
    }

    public function test_user_id_injecte_ignore(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $autre = User::factory()->create();

        $this->actingAs($user)->put($this->veilleNoteUrl($veille, $article), [
            'note' => 'ma note', 'user_id' => $autre->id,
        ]);

        $this->assertSame('ma note', $this->row($user->id, $article->id)?->note);
        $this->assertDatabaseMissing('article_user', ['user_id' => $autre->id]);
    }

    public function test_invite_redirige_vers_login(): void
    {
        [, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->put($this->veilleNoteUrl($veille, $article), ['note' => 'x'])->assertRedirect('/login');
        $this->delete(route('veilles.articles.note.delete', [$veille, $article]))->assertRedirect('/login');
    }
}
