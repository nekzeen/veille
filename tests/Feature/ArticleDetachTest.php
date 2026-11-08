<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleVeille;
use App\Models\User;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M12.21 — retrait d'un article d'une veille personnelle. */
class ArticleDetachTest extends TestCase
{
    use RefreshDatabase;

    private function assoc(Veille $v, Article $a): ArticleVeille
    {
        return ArticleVeille::create([
            'user_id' => $v->user_id, 'veille_id' => $v->id, 'article_id' => $a->id,
            'matched_keyword' => 'kw', 'matched_at' => now(),
        ]);
    }

    public function test_retrait_supprime_la_seule_association(): void
    {
        $u = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $a = Article::factory()->create();
        $this->assoc($v, $a);

        $this->actingAs($u)->delete("/veilles/{$v->id}/articles/{$a->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('article_veille', ['veille_id' => $v->id, 'article_id' => $a->id]);
        $this->assertNotNull($a->fresh()); // l'article partagé survit
        $this->assertNotNull($a->fresh()->last_detached_at); // dernier détachement daté
    }

    public function test_multi_veilles_meme_user_une_seule_retiree(): void
    {
        $u = User::factory()->create();
        $v1 = Veille::factory()->create(['user_id' => $u->id]);
        $v2 = Veille::factory()->create(['user_id' => $u->id]);
        $a = Article::factory()->create();
        $this->assoc($v1, $a);
        $this->assoc($v2, $a);

        $this->actingAs($u)->delete("/veilles/{$v1->id}/articles/{$a->id}")->assertRedirect();

        $this->assertDatabaseMissing('article_veille', ['veille_id' => $v1->id, 'article_id' => $a->id]);
        $this->assertDatabaseHas('article_veille', ['veille_id' => $v2->id, 'article_id' => $a->id]);
        $this->assertNull($a->fresh()->last_detached_at); // encore associé
    }

    public function test_isolation_autre_utilisateur(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $v1 = Veille::factory()->create(['user_id' => $u1->id]);
        $v2 = Veille::factory()->create(['user_id' => $u2->id]);
        $a = Article::factory()->create();
        $this->assoc($v1, $a);
        $this->assoc($v2, $a);

        $this->actingAs($u1)->delete("/veilles/{$v1->id}/articles/{$a->id}")->assertRedirect();
        $this->assertDatabaseHas('article_veille', ['veille_id' => $v2->id, 'article_id' => $a->id]);

        // Tentative sur la veille de u2 → 403.
        $this->actingAs($u1)->delete("/veilles/{$v2->id}/articles/{$a->id}")->assertForbidden();
        $this->assertDatabaseHas('article_veille', ['veille_id' => $v2->id, 'article_id' => $a->id]);
    }

    public function test_etats_personnels_conserves(): void
    {
        $u = User::factory()->create();
        $v = Veille::factory()->create(['user_id' => $u->id]);
        $a = Article::factory()->create();
        $this->assoc($v, $a);
        DB::table('article_user')->insert([
            'user_id' => $u->id, 'article_id' => $a->id, 'is_favorite' => true,
            'note' => 'ma note', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($u)->delete("/veilles/{$v->id}/articles/{$a->id}")->assertRedirect();

        $this->assertDatabaseHas('article_user', ['user_id' => $u->id, 'article_id' => $a->id, 'is_favorite' => 1]);
        $this->assertSame('ma note', DB::table('article_user')->where('article_id', $a->id)->value('note'));
    }
}
