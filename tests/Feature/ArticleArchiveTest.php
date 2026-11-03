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

class ArticleArchiveTest extends TestCase
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

    private function archiveUrl(Veille $veille, Article $article): string
    {
        return route('veilles.articles.archive', [$veille, $article]);
    }

    private function row(int $userId, int $articleId): ?ArticleUser
    {
        return ArticleUser::where('user_id', $userId)
            ->where('article_id', $articleId)->first();
    }

    public function test_archivage_et_desarchivage(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->actingAs($user)->put($this->archiveUrl($veille, $article))->assertRedirect();
        $this->assertTrue($this->row($user->id, $article->id)?->is_archived);
        $this->actingAs($user)->get(route('archives.index'))
            ->assertOk()->assertSee($article->title);

        $this->actingAs($user)->delete(route('articles.unarchive', $article))->assertRedirect();
        $this->assertNull($this->row($user->id, $article->id));
    }

    public function test_operations_idempotentes(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->actingAs($user)->put($this->archiveUrl($veille, $article));
        $this->actingAs($user)->put($this->archiveUrl($veille, $article));
        $this->assertSame(1, ArticleUser::where('user_id', $user->id)->count());

        $this->actingAs($user)->delete(route('articles.unarchive', $article));
        $this->actingAs($user)->delete(route('articles.unarchive', $article));
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

        $this->actingAs($userA)->put($this->archiveUrl($veilleA, $article));

        $this->assertDatabaseHas('article_user', [
            'user_id' => $userA->id, 'article_id' => $article->id, 'is_archived' => true,
        ]);
        $this->assertDatabaseMissing('article_user', ['user_id' => $userB->id]);
        // B ne voit ni l'archive de A ni l'article masqué dans sa propre liste.
        $this->actingAs($userB)->get(route('archives.index'))
            ->assertOk()->assertSee('aucun article archivé');
        $this->actingAs($userB)->get(route('veilles.articles.index', $veilleB))
            ->assertOk()->assertSee($article->title);
    }

    public function test_desarchivage_preserve_les_autres_donnees_personnelles(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $categoryId = DB::table('categories')->insertGetId([
            'user_id' => $user->id, 'name' => 'C', 'created_at' => now(), 'updated_at' => now(),
        ]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_read' => true, 'is_favorite' => true,
            'note' => 'n', 'category_id' => $categoryId, 'is_archived' => true,
        ]);

        $this->actingAs($user)->delete(route('articles.unarchive', $article));

        $row = $this->row($user->id, $article->id);
        $this->assertNotNull($row);
        $this->assertFalse($row->is_archived);
        $this->assertTrue($row->is_read && $row->is_favorite
            && $row->note === 'n' && $row->category_id === $categoryId);
    }

    public function test_archive_survit_a_la_suppression_de_la_correspondance(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, ['title' => 'Archivé persistant']);

        $this->actingAs($user)->put($this->archiveUrl($veille, $article));
        ArticleVeille::where('veille_id', $veille->id)
            ->where('article_id', $article->id)->delete();

        $this->actingAs($user)->get(route('archives.index'))
            ->assertOk()->assertSee('Archivé persistant');
        $this->actingAs($user)->get(route('articles.show', $article))->assertOk();
    }

    public function test_article_sans_acces_inaccessible(): void
    {
        $user = User::factory()->create();
        $article = Article::factory()->create();

        $this->actingAs($user)->put(route('articles.archive', $article))->assertNotFound();
        $this->actingAs($user)->get(route('archives.index'))->assertOk()
            ->assertSee('aucun article archivé');
    }

    public function test_archive_masque_des_listes_veille_et_favoris(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, ['title' => 'A masquer']);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_favorite' => true, 'is_archived' => true,
        ]);

        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertDontSee('A masquer');
        $this->actingAs($user)->get(route('favorites.index'))
            ->assertOk()->assertDontSee('A masquer');
        $this->actingAs($user)->get(route('archives.index'))
            ->assertOk()->assertSee('A masquer');
    }

    public function test_archive_seule_reste_purgeable(): void
    {
        // §4.E : is_archived seul ne protège pas — l'article hors fenêtre
        // disparaît de la veille ; seule sa ligne personnelle le rend
        // accessible (droit d'accès §4.D-bis, non protection de purge).
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, [
            'title' => 'Vieux archivé',
            'published_at' => now('UTC')->subMonths(7),
            'collected_at' => now('UTC')->subMonths(7),
        ]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_archived' => true,
        ]);

        $this->actingAs($user)->get(route('veilles.articles.index', $veille))
            ->assertOk()->assertDontSee('Vieux archivé');
        $this->actingAs($user)->get(route('archives.index'))
            ->assertOk()->assertSee('Vieux archivé');
        $this->actingAs($user)->get(route('articles.show', $article))->assertOk();
    }

    public function test_archive_plus_favori_ou_note_reste_accessible_et_protege(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, [
            'title' => 'Archivé favori',
            'published_at' => now('UTC')->subMonths(7),
            'collected_at' => now('UTC')->subMonths(7),
        ]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_archived' => true, 'is_favorite' => true, 'note' => 'n',
        ]);

        // Accessibilité personnelle garantie par favori+note (protecteurs).
        $this->actingAs($user)->get(route('articles.show', $article))->assertOk();
        $this->actingAs($user)->get(route('archives.index'))
            ->assertOk()->assertSee('Archivé favori');
    }

    public function test_page_archives_triee_et_paginee(): void
    {
        [$user, $veille] = $this->userWithVeille();
        for ($i = 1; $i <= 22; $i++) {
            $a = $this->match($veille, [
                'title' => "Arch {$i}",
                'published_at' => now('UTC')->subMinutes($i),
            ]);
            ArticleUser::factory()->create([
                'user_id' => $user->id, 'article_id' => $a->id, 'is_archived' => true,
            ]);
        }

        $this->actingAs($user)->get(route('archives.index'))
            ->assertOk()->assertSeeInOrder(['Arch 1', 'Arch 2'])
            ->assertDontSee('Arch 21');
        $this->actingAs($user)->get(route('archives.index', ['page' => 2]))
            ->assertOk()->assertSee('Arch 21');
    }

    public function test_user_id_injecte_ignore(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $autre = User::factory()->create();

        $this->actingAs($user)->put($this->archiveUrl($veille, $article), [
            'user_id' => $autre->id,
        ]);

        $this->assertTrue($this->row($user->id, $article->id)?->is_archived);
        $this->assertDatabaseMissing('article_user', ['user_id' => $autre->id]);
    }

    public function test_invite_redirige_vers_login(): void
    {
        [, $veille] = $this->userWithVeille();
        $article = $this->match($veille);

        $this->get(route('archives.index'))->assertRedirect('/login');
        $this->put(route('articles.archive', $article))->assertRedirect('/login');
        $this->delete(route('articles.unarchive', $article))->assertRedirect('/login');
    }
}
