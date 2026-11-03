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

/** Interactions croisées des états personnels (M1.13.1–13.4). */
class ArticleUserStatesTest extends TestCase
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

    private function row(int $userId, int $articleId): ?ArticleUser
    {
        return ArticleUser::where('user_id', $userId)
            ->where('article_id', $articleId)->first();
    }

    /** Ligne complète : lu + favori + note + catégorie + archivé. */
    private function fullRow(int $userId, int $articleId, ?int $categoryId = null): void
    {
        ArticleUser::factory()->create([
            'user_id' => $userId, 'article_id' => $articleId,
            'is_read' => true, 'is_favorite' => true,
            'note' => 'n', 'category_id' => $categoryId, 'is_archived' => true,
        ]);
    }

    public function test_quatre_etats_empiles_se_presentent_mutuellement(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $this->fullRow($user->id, $article->id);
        $v = fn ($path, $m = 'put', $data = []) => $this->actingAs($user)
            ->{$m}("/veilles/{$veille->id}/articles/{$article->id}/{$path}", $data);

        // Archivé masque la fiche veille → mutations via l'espace personnel.
        $this->actingAs($user)->get(route('articles.show', $article))->assertOk();

        $this->actingAs($user)->delete(route('articles.unarchive', $article));
        $r = $this->row($user->id, $article->id);
        $this->assertNotNull($r);                                    // lu+fav+note gardent la ligne
        $this->assertFalse($r->is_archived);
        $this->assertTrue($r->is_read && $r->is_favorite && $r->note === 'n');

        $v('favorite', 'delete');                                    // retrait favori
        $r = $this->row($user->id, $article->id);
        $this->assertFalse($r->is_favorite);
        $this->assertTrue($r->is_read && $r->note === 'n');

        $v('note', 'delete');                                        // suppression note
        $r = $this->row($user->id, $article->id);
        $this->assertNull($r->note);
        $this->assertTrue($r->is_read);

        $v('read', 'delete');                                        // non lu : plus rien → ligne supprimée
        $this->assertNull($this->row($user->id, $article->id));
    }

    public function test_desarchivage_sans_autre_donnee_supprime_la_ligne(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id, 'is_archived' => true,
        ]);

        $this->actingAs($user)->delete(route('articles.unarchive', $article));
        $this->assertNull($this->row($user->id, $article->id));
    }

    public function test_espace_personnel_sans_article_veille_tous_etats(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, ['title' => 'Autonome']);
        $this->fullRow($user->id, $article->id);
        ArticleVeille::where('veille_id', $veille->id)
            ->where('article_id', $article->id)->delete();

        // Tous les états survivent : accès via la ligne personnelle.
        $this->actingAs($user)->get(route('articles.show', $article))
            ->assertOk()->assertSee('Autonome')->assertSee('>Lu<', false)
            ->assertSee('Favori')->assertSee('n');
        $this->actingAs($user)->get(route('archives.index'))->assertSee('Autonome');
        $this->actingAs($user)->put(route('articles.note', $article), ['note' => 'm2'])
            ->assertRedirect();
        $this->assertSame('m2', $this->row($user->id, $article->id)?->note);
    }

    public function test_mutation_veille_404_apres_archivage_personnel_accessible(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $this->fullRow($user->id, $article->id);

        // Masqué de la veille → 404 côté veille, accessible en personnel.
        $this->actingAs($user)->get(route('veilles.articles.show', [$veille, $article]))
            ->assertNotFound();
        $this->actingAs($user)->put(route('articles.favorite', $article))->assertRedirect();
    }

    public function test_categorie_preservee_lors_du_desarchivage(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $categoryId = DB::table('categories')->insertGetId([
            'user_id' => $user->id, 'name' => 'C', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fullRow($user->id, $article->id, $categoryId);

        $this->actingAs($user)->delete(route('articles.unarchive', $article));
        $r = $this->row($user->id, $article->id);
        $this->assertSame($categoryId, $r->category_id);
        $this->assertTrue($r->is_read && $r->is_favorite && $r->note === 'n');
    }
}
