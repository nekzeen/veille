<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\Category;
use App\Models\User;
use App\Models\Veille;
use App\Services\Articles\ArticleCategoryState;
use App\Services\Articles\ArticlePersonalAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ArticleCategoryStateTest extends TestCase
{
    use RefreshDatabase;

    private ArticleCategoryState $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = app(ArticleCategoryState::class);
    }

    private function userWithArticle(): array
    {
        $user = User::factory()->create();
        $veille = Veille::factory()->create(['user_id' => $user->id]);
        $article = Article::factory()->create();
        ArticleVeille::factory()->create([
            'veille_id' => $veille->id, 'article_id' => $article->id,
        ]);

        return [$user, $article];
    }

    private function row(int $userId, int $articleId): ?ArticleUser
    {
        return ArticleUser::where('user_id', $userId)
            ->where('article_id', $articleId)->first();
    }

    public function test_creation_nettoyage_et_longueur(): void
    {
        $user = User::factory()->create();

        $cat = $this->state->create($user, '  Veille tech  ');
        $this->assertSame('Veille tech', $cat->name);
        $this->assertSame($user->id, $cat->user_id);

        $this->state->create($user, str_repeat('a', 100));
        $this->expectException(ValidationException::class);
        $this->state->create($user, str_repeat('a', 101));
    }

    public function test_nom_vide_refuse(): void
    {
        $this->expectException(ValidationException::class);
        $this->state->create(User::factory()->create(), "   \n\t ");
    }

    public function test_unicite_par_utilisateur_creation_et_renommage(): void
    {
        $user = User::factory()->create();
        $a = $this->state->create($user, 'Alpha');
        $this->state->create($user, 'Bêta');

        $this->expectException(ValidationException::class);
        try {
            $this->state->create($user, 'Alpha');
            $this->fail('doublon création');
        } catch (ValidationException $e) {
            $this->state->rename($a, 'Bêta');   // vers un nom existant → refusé
            $this->fail('doublon renommage');
        }
    }

    public function test_meme_nom_entre_utilisateurs_autorise(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $this->state->create($u1, 'Tech');
        $this->state->create($u2, 'Tech');

        $this->assertSame(2, Category::where('name', 'Tech')->count());
    }

    public function test_renommage_ok_et_vers_soi_meme(): void
    {
        $user = User::factory()->create();
        $cat = $this->state->create($user, 'Ancien');

        $cat = $this->state->rename($cat, 'Nouveau');
        $this->assertSame('Nouveau', $cat->name);
        $cat = $this->state->rename($cat, 'Nouveau');   // idempotent
        $this->assertSame('Nouveau', $cat->name);
    }

    public function test_categorie_dun_autre_utilisateur_refusee(): void
    {
        [$user, $article] = $this->userWithArticle();
        $autre = User::factory()->create();
        $etrangere = Category::factory()->create(['user_id' => $autre->id]);

        $this->expectException(ModelNotFoundException::class);
        $this->state->setCategory($user, $article, $etrangere);
    }

    public function test_article_sans_acces_refuse(): void
    {
        $user = User::factory()->create();
        $cat = Category::factory()->create(['user_id' => $user->id]);
        $article = Article::factory()->create();   // aucune veille, aucune ligne

        $this->expectException(ModelNotFoundException::class);
        $this->state->setCategory($user, $article, $cat);
    }

    public function test_affectation_et_retrait(): void
    {
        [$user, $article] = $this->userWithArticle();
        $cat = Category::factory()->create(['user_id' => $user->id]);

        $this->state->setCategory($user, $article, $cat);
        $this->assertSame($cat->id, $this->row($user->id, $article->id)?->category_id);

        // Ligne n'ayant que la catégorie → supprimée au retrait.
        $this->state->clearCategory($user, $article);
        $this->assertNull($this->row($user->id, $article->id));
    }

    public function test_affectation_preserve_les_autres_etats(): void
    {
        [$user, $article] = $this->userWithArticle();
        $cat = Category::factory()->create(['user_id' => $user->id]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $article->id,
            'is_read' => true, 'is_favorite' => true, 'note' => 'n',
        ]);

        $this->state->setCategory($user, $article, $cat);
        $r = $this->row($user->id, $article->id);
        $this->assertSame($cat->id, $r->category_id);
        $this->assertTrue($r->is_read && $r->is_favorite && $r->note === 'n');

        // Retrait : ligne conservée, seule category_id → NULL.
        $this->state->clearCategory($user, $article);
        $r = $this->row($user->id, $article->id);
        $this->assertNotNull($r);
        $this->assertNull($r->category_id);
        $this->assertTrue($r->is_read && $r->is_favorite && $r->note === 'n');
    }

    public function test_suppression_categorie_multi_articles(): void
    {
        [$user, $a1] = $this->userWithArticle();
        $a2 = Article::factory()->create();
        ArticleVeille::factory()->create([
            'veille_id' => Veille::factory()->create(['user_id' => $user->id])->id,
            'article_id' => $a2->id,
        ]);
        $cat = Category::factory()->create(['user_id' => $user->id]);

        // a1 : catégorie seule → ligne élaguée ; a2 : avec favori → conservée.
        $this->state->setCategory($user, $a1, $cat);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $a2->id,
            'category_id' => $cat->id, 'is_favorite' => true,
        ]);

        $this->state->delete($cat);

        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
        $this->assertNull($this->row($user->id, $a1->id));
        $r = $this->row($user->id, $a2->id);
        $this->assertNotNull($r);
        $this->assertNull($r->category_id);
        $this->assertTrue($r->is_favorite);
    }

    public function test_categorie_donne_acces_autonome_et_protege(): void
    {
        [$user, $article] = $this->userWithArticle();
        $cat = Category::factory()->create(['user_id' => $user->id]);
        $this->state->setCategory($user, $article, $cat);

        // Correspondance supprimée + hors fenêtre : la catégorie
        // conserve l'accès personnel (§4.D-bis) et la protection (§4.E).
        ArticleVeille::where('article_id', $article->id)->delete();
        $article->update([
            'published_at' => now('UTC')->subMonths(7),
            'collected_at' => now('UTC')->subMonths(7),
        ]);

        $this->assertTrue(app(ArticlePersonalAccess::class)->hasAccess($user, $article));
    }

    public function test_compteur_limite_aux_non_archives(): void
    {
        [$user, $a1] = $this->userWithArticle();
        $a2 = Article::factory()->create();
        $cat = Category::factory()->create(['user_id' => $user->id]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $a1->id, 'category_id' => $cat->id,
        ]);
        ArticleUser::factory()->create([
            'user_id' => $user->id, 'article_id' => $a2->id,
            'category_id' => $cat->id, 'is_archived' => true,
        ]);

        $cats = $this->state->categories($user);
        $this->assertSame(1, $cats->count());
        $this->assertSame(1, $cats->first()->articles_count);
    }
}
