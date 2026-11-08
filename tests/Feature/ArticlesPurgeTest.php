<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleUser;
use App\Models\ArticleVeille;
use App\Models\Source;
use App\Models\User;
use App\Models\Veille;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** M12.11 corrigée — purge rétention : dry-run par défaut, grâce réelle par last_detached_at. */
class ArticlesPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $u = User::factory()->create();

        return [$u, Veille::factory()->create(['user_id' => $u->id])];
    }

    private function article(string $url, int $monthsAgo = 8, ?string $detached = null): Article
    {
        $guid = 'g-'.md5($url);
        $a = Article::create([
            'source_id' => Source::factory()->create()->id,
            'guid' => $guid, 'dedup_key' => hash('sha256', $guid),
            'url' => $url, 'url_hash' => hash('sha256', $url),
            'title' => 'A '.$url, 'summary' => 'R', 'content' => '<p>C</p>',
            'collected_at' => now('UTC')->subMonths($monthsAgo),
            'published_at' => now('UTC')->subMonths($monthsAgo),
        ]);
        Article::where('id', $a->id)->update([
            'last_detached_at' => $detached === null ? null : now('UTC')->parse($detached),
        ]);

        return $a;
    }

    private function assoc(Veille $v, Article $a, int $uid): void
    {
        ArticleVeille::create(['veille_id' => $v->id, 'article_id' => $a->id, 'user_id' => $uid, 'matched_keyword' => 'k']);
    }

    // ---------- Options de la commande ----------

    public function test_dry_run_est_le_defaut(): void
    {
        [$u, $v] = $this->fixture();
        $a = $this->article('https://ex.fr/x', 8, '-10 days');
        $this->artisan('articles:purge')->expectsOutputToContain('DRY-RUN')->assertOk();
        $this->assertNotNull($a->fresh());
        $this->artisan('articles:purge', ['--dry-run' => true])->assertOk();
        $this->assertNotNull($a->fresh());
    }

    public function test_options_contradictoires_refusees(): void
    {
        $this->artisan('articles:purge', ['--dry-run' => true, '--execute' => true])
            ->assertFailed();
    }

    // ---------- Étape A : détachement daté ----------

    public function test_dernier_detachement_date_atomiquement(): void
    {
        [$u, $v] = $this->fixture();
        $a = $this->article('https://ex.fr/old', 8);
        $this->assoc($v, $a, $u->id);

        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNull(ArticleVeille::where('article_id', $a->id)->first());
        $this->assertNotNull($a->fresh()->last_detached_at); // daté à l'instant
        $this->assertNotNull($a->fresh());                    // jamais purgé le même jour
    }

    public function test_article_associé_jamais_supprime(): void
    {
        [$u, $v] = $this->fixture();
        $a = $this->article('https://ex.fr/assoc', 8, '-30 days');
        $this->assoc($v, $a, $u->id);
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNotNull($a->fresh()); // encore associé → pas candidat B
    }

    // ---------- Grâce 7 jours ----------

    public function test_purge_interdite_avant_sept_jours(): void
    {
        $a = $this->article('https://ex.fr/early', 8, '-3 days');
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNotNull($a->fresh());
    }

    public function test_purge_autorisee_apres_sept_jours(): void
    {
        $a = $this->article('https://ex.fr/eligible', 8, '-10 days');
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNull($a->fresh());
    }

    public function test_orphelin_preexistant_grace_fraiche(): void
    {
        // Orphelin SANS last_detached_at → daté maintenant, jamais purgé ce tour.
        $a = $this->article('https://ex.fr/pre', 8);
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $f = $a->fresh();
        $this->assertNotNull($f);
        $this->assertNotNull($f->last_detached_at);
    }

    // ---------- Articles jamais associés ----------

    public function test_jamais_associé_moins_de_6_mois(): void
    {
        $a = $this->article('https://ex.fr/young', 3, '-30 days');
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNotNull($a->fresh()); // trop jeune → jamais éligible
    }

    public function test_jamais_associé_plus_de_6_mois_avec_grace(): void
    {
        $a = $this->article('https://ex.fr/oldorphan', 8, '-30 days');
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNull($a->fresh());
    }

    public function test_publication_ancienne_collecte_recente_eligible(): void
    {
        // COALESCE : published_at ancien l'emporte → éligible (grâce passée).
        $a = $this->article('https://ex.fr/mix', 8, '-30 days');
        Article::where('id', $a->id)->update(['collected_at' => now('UTC')->subDays(2)]);
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNull($a->fresh());
    }

    public function test_publication_recente_collecte_ancienne_protegee(): void
    {
        // published_at récent → COALESCE récent → jamais éligible.
        $a = $this->article('https://ex.fr/recent', 8, '-30 days');
        Article::where('id', $a->id)->update(['published_at' => now('UTC')->subDays(5)]);
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNotNull($a->fresh());
    }

    public function test_published_at_null_replie_sur_collected_at(): void
    {
        $a = $this->article('https://ex.fr/nopub', 8, '-30 days');
        Article::where('id', $a->id)->update(['published_at' => null]);
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNull($a->fresh()); // collected_at 8mo → éligible
    }

    // ---------- Données personnelles ----------

    public function test_favori_note_archive_bloquent(): void
    {
        [$u] = $this->fixture();
        foreach (['fav' => ['is_favorite' => true], 'arc' => ['is_archived' => true], 'note' => ['note' => 'x']] as $k => $attrs) {
            $a = $this->article("https://ex.fr/$k", 8, '-30 days');
            ArticleUser::create(['user_id' => $u->id, 'article_id' => $a->id] + $attrs);
        }
        $ro = $this->article('https://ex.fr/ro', 8, '-30 days');
        ArticleUser::create(['user_id' => $u->id, 'article_id' => $ro->id, 'is_read' => true]);

        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertSame(3, Article::whereIn('url', ['https://ex.fr/fav', 'https://ex.fr/arc', 'https://ex.fr/note'])->count());
        $this->assertNull($ro->fresh()); // is_read seul : pas de blocage
    }

    public function test_isolation_multi_utilisateurs(): void
    {
        [$u1, $v1] = $this->fixture();
        [$u2, $v2] = $this->fixture();
        $a = $this->article('https://ex.fr/sh', 8);
        $this->assoc($v1, $a, $u1->id);
        $this->assoc($v2, $a, $u2->id);
        ArticleUser::create(['user_id' => $u2->id, 'article_id' => $a->id, 'is_favorite' => true]);

        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNotNull($a->fresh());            // favori u2 protège
        $this->assertSame(1, ArticleUser::where('article_id', $a->id)->count());
    }

    // ---------- Courses / reprise ----------

    public function test_nouvelle_association_pendant_purge_protege(): void
    {
        // Article éligible par grâce MAIS une veille le réassocie :
        // le NOT EXISTS du DELETE le revérifie à l'exécution.
        [$u, $v] = $this->fixture();
        $a = $this->article('https://ex.fr/race', 8, '-30 days');
        $this->assoc($v, $a, $u->id);
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNotNull($a->fresh());
    }

    public function test_reprise_idempotente(): void
    {
        $a = $this->article('https://ex.fr/rep', 8, '-30 days');
        $this->artisan('articles:purge', ['--execute' => true])->assertOk();
        $this->assertNull($a->fresh());
        $this->artisan('articles:purge', ['--execute' => true])->assertOk(); // 0 travail
        $this->assertNull($a->fresh());
    }
}
