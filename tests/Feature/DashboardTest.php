<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
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
}
