<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use App\Services\Articles\ArticleReadState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_indicateurs_et_contenu_de_l_utilisateur(): void
    {
        $user = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $user->id, 'is_active' => true]);
        $source = Source::factory()->create(['is_active' => true]);
        $v->sources()->attach($source->id);
        $article = Article::factory()->create(['source_id' => $source->id]);
        ArticleVeille::create([
            'veille_id' => $v->id,
            'article_id' => $article->id,
            'user_id' => $user->id,
            'matched_keyword' => 'paris',
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Veilles actives')
            ->assertSee('Non lus')
            ->assertSee($v->name)
            ->assertSee('paris')
            ->assertSee($article->title);
    }

    public function test_isolation_stricte_entre_utilisateurs(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $vb = Veille::factory()->create(['user_id' => $b->id, 'name' => 'VeilleB-Secrete']);
        $sb = Source::factory()->create();
        $vb->sources()->attach($sb->id);
        $ab = Article::factory()->create(['source_id' => $sb->id, 'title' => 'Article-De-B']);
        ArticleVeille::create([
            'veille_id' => $vb->id,
            'article_id' => $ab->id,
            'user_id' => $b->id,
            'matched_keyword' => 'motb',
        ]);

        $this->actingAs($a)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('VeilleB-Secrete')
            ->assertDontSee('Article-De-B')
            ->assertDontSee('motb');
    }

    public function test_etats_vides(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Aucune veille pour le moment')
            ->assertSee('Aucun article détecté');
    }

    public function test_invite_est_redirige(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_recents_excluent_lus_et_dedoublonnent(): void
    {
        $user = User::factory()->create();
        $v1 = Veille::factory()->create(['user_id' => $user->id]);
        $v2 = Veille::factory()->create(['user_id' => $user->id]);
        $s = Source::factory()->create();
        $a1 = Article::factory()->create(['source_id' => $s->id, 'title' => 'ArticleReadXYZ']);
        $a2 = Article::factory()->create(['source_id' => $s->id, 'title' => 'ArticleUnreadXYZ']);
        ArticleVeille::create(['veille_id' => $v1->id, 'article_id' => $a1->id, 'user_id' => $user->id, 'matched_keyword' => 'k']);
        ArticleVeille::create(['veille_id' => $v1->id, 'article_id' => $a2->id, 'user_id' => $user->id, 'matched_keyword' => 'k']);
        // a2 aussi dans v2 : ne doit compter qu'une fois.
        ArticleVeille::create(['veille_id' => $v2->id, 'article_id' => $a2->id, 'user_id' => $user->id, 'matched_keyword' => 'k']);
        app(ArticleReadState::class)->markRead($user, $a1);

        $r = $this->actingAs($user)->get('/dashboard')->assertOk();
        $r->assertSee('ArticleUnreadXYZ')->assertDontSee('ArticleReadXYZ');
        $r->assertViewHas('stats', fn ($s) => $s['articles_non_lus'] === 1);
    }

    public function test_recents_reapparait_apres_non_lu_et_isolation(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $u1->id]);
        $s = Source::factory()->create();
        $a = Article::factory()->create(['source_id' => $s->id, 'title' => 'ToggleXYZ']);
        ArticleVeille::create(['veille_id' => $v->id, 'article_id' => $a->id, 'user_id' => $u1->id, 'matched_keyword' => 'k']);
        $rs = app(ArticleReadState::class);
        $rs->markRead($u2, $a);
        $this->actingAs($u1)->get('/dashboard')->assertSee('ToggleXYZ');
        $rs->markRead($u1, $a);
        $this->actingAs($u1)->get('/dashboard')->assertDontSee('ToggleXYZ');
        $rs->markUnread($u1, $a);
        $this->actingAs($u1)->get('/dashboard')->assertSee('ToggleXYZ');
    }
}
