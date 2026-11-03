<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\Category;
use App\Models\User;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryUiTest extends TestCase
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

    public function test_invite_redirige_vers_login(): void
    {
        $this->get(route('categories.index'))->assertRedirect('/login');
        $this->post(route('categories.store'), ['name' => 'x'])->assertRedirect('/login');
        $this->get('/categories/1/articles')->assertRedirect('/login');
    }

    public function test_crud_categories(): void
    {
        [$user] = [User::factory()->create()];

        $this->actingAs($user)->get(route('categories.index'))->assertOk();

        $this->actingAs($user)->post(route('categories.store'), ['name' => '  Veille tech  '])
            ->assertRedirect(route('categories.index'));
        $cat = Category::where('user_id', $user->id)->sole();
        $this->assertSame('Veille tech', $cat->name);

        $this->actingAs($user)->patch(route('categories.update', $cat), ['name' => 'Tech v2'])
            ->assertRedirect(route('categories.index'));
        $this->assertSame('Tech v2', $cat->fresh()->name);

        $this->actingAs($user)->delete(route('categories.destroy', $cat))
            ->assertRedirect(route('categories.index'));
        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }

    public function test_unicite_par_utilisateur(): void
    {
        [$user] = [User::factory()->create()];
        $this->actingAs($user)->post(route('categories.store'), ['name' => 'Tech']);
        $this->actingAs($user)->post(route('categories.store'), ['name' => 'Tech'])
            ->assertSessionHasErrors();
        $this->assertSame(1, Category::where('user_id', $user->id)->count());

        $cat = Category::where('user_id', $user->id)->sole();
        $this->actingAs($user)->post(route('categories.store'), ['name' => 'Autre']);
        $autre = Category::where('name', 'Autre')->sole();
        $this->actingAs($user)->patch(route('categories.update', $autre), ['name' => 'Tech'])
            ->assertSessionHasErrors();
        $this->assertSame('Autre', $autre->fresh()->name);
        $this->actingAs($user)->patch(route('categories.update', $cat), ['name' => 'Tech'])
            ->assertRedirect(route('categories.index'));
    }

    public function test_acces_interdit_categories_dautrui(): void
    {
        $proprietaire = User::factory()->create();
        $cat = Category::factory()->create([
            'user_id' => $proprietaire->id, 'name' => 'privée',
        ]);
        $intrus = User::factory()->create();

        $this->actingAs($intrus)->get(route('categories.index'))
            ->assertOk()->assertDontSee('privée');
        $this->actingAs($intrus)->patch(route('categories.update', $cat), ['name' => 'x'])
            ->assertNotFound();
        $this->actingAs($intrus)->delete(route('categories.destroy', $cat))
            ->assertNotFound();
        $this->actingAs($intrus)->get(route('categories.articles', $cat))
            ->assertNotFound();
        $this->assertSame('privée', $cat->fresh()->name);
    }

    public function test_affectation_retrait_fiche_veille(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $cat = Category::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->put(
            route('veilles.articles.category', [$veille, $article]),
            ['category_id' => $cat->id])->assertRedirect();
        $this->assertSame($cat->id, ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)->value('category_id'));

        $this->actingAs($user)->get(route('categories.articles', $cat))
            ->assertOk()->assertSee($article->title);

        // Changement de catégorie : la nouvelle remplace l'ancienne.
        $cat2 = Category::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->put(
            route('veilles.articles.category', [$veille, $article]),
            ['category_id' => $cat2->id])->assertRedirect();
        $this->assertSame($cat2->id, ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)->value('category_id'));

        $this->actingAs($user)->delete(
            route('veilles.articles.category.delete', [$veille, $article]))
            ->assertRedirect();
        $this->assertNull(ArticleUser::where('user_id', $user->id)
            ->where('article_id', $article->id)->first());
    }

    public function test_affectation_retrait_fiche_personnelle(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $cat = Category::factory()->create(['user_id' => $user->id]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id, 'is_favorite' => true,
        ]);

        $this->actingAs($user)->put(route('articles.category', $article),
            ['category_id' => $cat->id, 'return' => 'article'])
            ->assertRedirect(route('articles.show', $article));
        $this->assertSame($cat->id, ArticleUser::where('user_id', $user->id)
            ->value('category_id'));

        $this->actingAs($user)->delete(route('articles.category.delete', $article))
            ->assertRedirect();
        $row = ArticleUser::where('user_id', $user->id)->sole();
        $this->assertNull($row->category_id);
        $this->assertTrue($row->is_favorite);   // favori préservé
    }

    public function test_categorie_dautrui_non_affectable(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $etrangere = Category::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($user)->put(
            route('veilles.articles.category', [$veille, $article]),
            ['category_id' => $etrangere->id])->assertNotFound();
        $this->actingAs($user)->put(route('articles.category', $article),
            ['category_id' => $etrangere->id])->assertNotFound();
        $this->assertNull(ArticleUser::where('user_id', $user->id)->first());
    }

    public function test_affectation_preserve_les_etats(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $cat = Category::factory()->create(['user_id' => $user->id]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_read' => true, 'is_favorite' => true, 'note' => 'n',
        ]);

        $this->actingAs($user)->put(route('articles.category', $article),
            ['category_id' => $cat->id]);
        $r = ArticleUser::where('user_id', $user->id)->sole();
        $this->assertSame($cat->id, $r->category_id);
        $this->assertTrue($r->is_read && $r->is_favorite && $r->note === 'n');
    }

    public function test_liste_cat_masque_archives_et_pagine(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $cat = Category::factory()->create(['user_id' => $user->id]);
        for ($i = 1; $i <= 22; $i++) {
            $a = $this->match($veille, [
                'title' => "Art {$i}", 'published_at' => now('UTC')->subMinutes($i),
            ]);
            ArticleUser::factory()->create([
                'user_id' => $user->id, 'article_id' => $a->id,
                'category_id' => $cat->id, 'is_archived' => $i === 22,
            ]);
        }

        $this->actingAs($user)->get(route('categories.articles', $cat))
            ->assertOk()->assertSeeInOrder(['Art 1', 'Art 2'])
            ->assertDontSee('Art 21');
        $this->actingAs($user)->get(route('categories.articles', [$cat, 'page' => 2]))
            ->assertOk()->assertSee('Art 21')->assertDontSee('Art 22');   // archivé masqué
    }

    public function test_article_accessible_sans_article_veille(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille, ['title' => 'Classé autonome']);
        $cat = Category::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->put(route('articles.category', $article),
            ['category_id' => $cat->id]);
        ArticleVeille::where('article_id', $article->id)->delete();

        $this->actingAs($user)->get(route('categories.articles', $cat))
            ->assertOk()->assertSee('Classé autonome');
        $this->actingAs($user)->get(route('articles.show', $article))->assertOk();
    }

    public function test_user_id_injecte_ignore(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $cat = Category::factory()->create(['user_id' => $user->id]);
        $autre = User::factory()->create();

        $this->actingAs($user)->post(route('categories.store'),
            ['name' => 'Inject', 'user_id' => $autre->id]);
        $this->assertSame($user->id, Category::where('name', 'Inject')->value('user_id'));
        $this->assertDatabaseMissing('categories', [
            'user_id' => $autre->id, 'name' => 'Inject',
        ]);
    }

    public function test_suppression_cat_dissocie_ses_articles(): void
    {
        [$user, $veille] = $this->userWithVeille();
        $article = $this->match($veille);
        $cat = Category::factory()->create(['user_id' => $user->id]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'category_id' => $cat->id, 'is_favorite' => true,
        ]);

        $this->actingAs($user)->delete(route('categories.destroy', $cat));

        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
        $r = ArticleUser::where('user_id', $user->id)->sole();
        $this->assertNull($r->category_id);
        $this->assertTrue($r->is_favorite);
    }
}
