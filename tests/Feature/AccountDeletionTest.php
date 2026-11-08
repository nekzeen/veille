<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\UserExclusion;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** M12.6 — suppression complète d'un compte. */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $uid, int $vid): void
    {
        $a = Article::factory()->create();
        ArticleVeille::create(['veille_id' => $vid, 'article_id' => $a->id, 'user_id' => $uid, 'matched_keyword' => 'k']);
        ArticleUser::create(['user_id' => $uid, 'article_id' => $a->id, 'is_favorite' => true, 'note' => 'n']);
        UserExclusion::create(['user_id' => $uid, 'kind' => 'url', 'pattern' => 'x.com']);
        DB::table('sessions')->insert(['id' => 's'.$uid, 'user_id' => $uid, 'ip_address' => '1.2.3.4', 'user_agent' => 't', 'payload' => 'e', 'last_activity' => time()]);

        DB::table('veille_shares')->insert(['user_id' => $uid, 'veille_id' => null, 'scope' => 'all', 'token_hash' => hash('sha256', 't'.$uid), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_suppression_complete_avec_mot_de_passe(): void
    {
        $u = User::factory()->create(['password' => 'motdepasse-fort-42']);
        $vid = Veille::factory()->create(['user_id' => $u->id])->id;
        $this->fixture($u->id, $vid);

        $this->actingAs($u)
            ->delete('/profile', ['password' => 'motdepasse-fort-42'])
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull(User::find($u->id));
        $this->assertNull(Veille::find($vid));
        $this->assertSame(0, ArticleUser::where('user_id', $u->id)->count());
        $this->assertSame(0, UserExclusion::where('user_id', $u->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $u->id)->count());

        $this->assertSame(0, DB::table('veille_shares')->where('user_id', $u->id)->count());
        // Articles et sources communs intacts.
        $this->assertNotNull(Article::first());
        $this->assertNotNull(Source::first());
    }

    public function test_mot_de_passe_incorrect_refuse(): void
    {
        $u = User::factory()->create(['password' => 'motdepasse-fort-42']);
        $this->actingAs($u)->delete('/profile', ['password' => 'faux'])
            ->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertNotNull($u->fresh());
    }

    public function test_isolation_apres_suppression(): void
    {
        $u1 = User::factory()->create(['password' => 'motdepasse-fort-42']);
        $u2 = User::factory()->create();
        $s = Source::factory()->create();
        $a = Article::factory()->create(['source_id' => $s->id]);
        $v1 = Veille::factory()->create(['user_id' => $u1->id]);
        $v2 = Veille::factory()->create(['user_id' => $u2->id]);
        // Article commun aux deux utilisateurs.
        ArticleVeille::create(['veille_id' => $v1->id, 'article_id' => $a->id, 'user_id' => $u1->id, 'matched_keyword' => 'k']);
        ArticleVeille::create(['veille_id' => $v2->id, 'article_id' => $a->id, 'user_id' => $u2->id, 'matched_keyword' => 'k']);
        ArticleUser::create(['user_id' => $u2->id, 'article_id' => $a->id, 'is_read' => true]);

        $this->actingAs($u1)->delete('/profile', ['password' => 'motdepasse-fort-42']);

        // u2 et le catalogue commun intacts.
        $this->assertNotNull($u2->fresh());
        $this->assertNotNull($v2->fresh());
        $this->assertNotNull($a->fresh());
        $this->assertSame(1, ArticleUser::where('user_id', $u2->id)->count());
        $this->assertSame(1, ArticleVeille::where('user_id', $u2->id)->count());
    }

    public function test_lien_partage_revoque_apres_suppression(): void
    {
        $u = User::factory()->create(['password' => 'motdepasse-fort-42']);
        $token = 'lien-public-xyz';
        DB::table('veille_shares')->insert(['user_id' => $u->id, 'veille_id' => null, 'scope' => 'all', 'token_hash' => hash('sha256', $token), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($u)->delete('/profile', ['password' => 'motdepasse-fort-42']);
        $this->get('/shared/'.$token)->assertNotFound(); // plus aucun share valide
    }
}
